<?php

use App\Modules\Billing\Actions\RefundAccountCredit;
use App\Modules\Billing\Actions\RefundTransaction;
use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Enums\CreditReason;
use App\Modules\Billing\Enums\RefundMode;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Billing\Services\CreditLedgerService;
use App\Modules\Billing\Services\SettledRefundCheck;
use App\Modules\Core\Models\Client;
use App\Modules\PaymentsNepal\Drivers\KhaltiDriver;
use App\Support\Dashboard\Dashboard;
use App\Support\Money;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/helpers.php';

/*
| PR #474 review probes (adopted): refunds to account credit hide a portal refund; a portal flag overwrites an open late
| or credit flag. Plus the two cases the fix round added: a payment kept as credit, and a partial answer after a recorded refund.
*/

beforeEach(function () {
    Http::preventStrayRequests();
    nepalGateway(KhaltiDriver::KEY, ['sandbox' => '1', 'secret_key' => 'test_secret_probe']);
    $this->answer = 'Refunded';
    Http::fake([
        'https://dev.khalti.com/api/v2/epayment/lookup/' => fn (Request $request) => Http::response(['pidx' => $request['pidx'], 'total_amount' => 150_000, 'status' => $this->answer, 'transaction_id' => 'TXN-'.$request['pidx'], 'fee' => 300, 'refunded' => true]),
    ]);
});

/**
 * A booked Khalti attempt of Rs 1,500 on a new NPR invoice (or on $paidFirst's invoice, already paid by bank transfer,
 * so the Khalti payment goes to account credit).
 *
 * @param  array<string, mixed>  $extra  attempt columns
 * @return array{0: PaymentAttempt, 1: Transaction}
 */
function probeSettledKhalti(array $extra = [], bool $paidFirst = false): array
{
    [$client, $invoice] = nprInvoice([150_000]);

    if ($paidFirst) {
        payInvoice($invoice, 150_000, 'BANK-'.$invoice->id);
    }

    $payment = payInvoice($invoice->fresh(), 150_000, 'TXN-P'.$invoice->id, KhaltiDriver::KEY);
    $attempt = PaymentAttempt::query()->create([
        'reference' => PaymentAttempt::newReference(), 'gateway' => KhaltiDriver::KEY, 'client_id' => $client->id, 'invoice_id' => $invoice->id,
        'amount' => 150_000, 'currency' => 'NPR', 'document_amount' => 150_000, 'document_currency' => 'NPR', 'status' => AttemptStatus::Succeeded,
        'provider_ref' => 'P'.$invoice->id, 'provider_txn_id' => 'TXN-P'.$invoice->id, 'transaction_id' => $payment->id, 'last_lookup_at' => now(), 'lookup_count' => 1,
        ...$extra,
    ]);

    return [$attempt, $payment->fresh()];
}

test('probe A: a full refund to account credit, then a full refund in the Khalti portal, is flagged', function () {
    [$attempt, $payment] = probeSettledKhalti();
    app(RefundTransaction::class)->handle($payment, 150_000, RefundMode::ToCredit);
    $this->travel(1)->days();
    app(SettledRefundCheck::class)->run();

    // The client holds Rs 1,500 credit AND got Rs 1,500 back in the Khalti portal.
    expect($attempt->fresh()->check_reason)->toBe(PaymentAttempt::CHECK_PORTAL_REFUND)
        ->and($attempt->fresh()->raw[SettledRefundCheck::RAW_KEY]['refunded_here'])->toBe(0);
});

test('probe B: a goodwill partial refund to credit, then a partial refund in the Khalti portal, is flagged', function () {
    [$attempt, $payment] = probeSettledKhalti();
    app(RefundTransaction::class)->handle($payment, 20_000, RefundMode::ToCredit, reason: 'Goodwill credit');
    $this->answer = 'Partially refunded';
    $this->travel(1)->days();
    app(SettledRefundCheck::class)->run();

    expect($attempt->fresh()->check_reason)->toBe(PaymentAttempt::CHECK_PORTAL_REFUND);
});

test('probe C: an open credit flag overwritten by the portal flag keeps its reason somewhere staff see', function () {
    [$attempt] = probeSettledKhalti(['check_reason' => PaymentAttempt::CHECK_CREDIT, 'review_reason' => 'Invoice already paid: kept as account credit']);
    $this->travel(1)->days();
    app(SettledRefundCheck::class)->run();

    $fresh = $attempt->fresh();
    expect($fresh->review_reason)->toContain('account credit')->toEndWith('; was: Invoice already paid: kept as account credit')
        ->and($fresh->raw[SettledRefundCheck::RAW_KEY]['previous_check'])->toBe(['check_reason' => PaymentAttempt::CHECK_CREDIT, 'review_reason' => 'Invoice already paid: kept as account credit']);
});

test('a payment kept as account credit points staff to Refund account credit, not the invoice', function () {
    [$attempt, $payment] = probeSettledKhalti(paidFirst: true);
    $this->travel(1)->days();
    app(SettledRefundCheck::class)->run();

    expect($payment->refundable_remaining)->toBe(0)
        ->and($attempt->fresh()->review_reason)->toBe('Refunded in full at the provider, but not recorded here: the payment went to account credit, so record it with Refund account credit')
        ->and($attempt->fresh()->raw[SettledRefundCheck::RAW_KEY]['credited'])->toBe(150_000);
});

test('a partial answer after a refund recorded here is not flagged (a second partial portal refund is not told apart)', function () {
    [$attempt, $payment] = probeSettledKhalti();
    app(RefundTransaction::class)->handle($payment, 20_000, RefundMode::RecordOnly);
    $this->answer = 'Partially refunded';
    $this->travel(1)->days();

    expect(app(SettledRefundCheck::class)->run())->toBe(1)
        ->and($attempt->fresh()->check_reason)->toBeNull();
});

test('the "Gateway payments to review" to-do opens a list that shows the payments it counts', function () {
    [$refunded] = probeSettledKhalti(['check_reason' => PaymentAttempt::CHECK_REFUND, 'review_reason' => 'Refunded at the provider beyond the account credit left']);
    $todo = collect(app(Dashboard::class)->resolveTodos('admin', billingStaff()))->firstWhere('key', 'billing.payment_review');

    expect($todo['count'])->toBe(1);
    $this->get($todo['href'])->assertOk()
        ->assertInertia(fn ($page) => $page->has('attempts.data', 1)->where('attempts.data.0.id', $refunded->id));
});

test('RR-D: after a full refund to credit, the portal flag points staff to Refund account credit', function () {
    [$attempt, $payment] = probeSettledKhalti();
    app(RefundTransaction::class)->handle($payment, 150_000, RefundMode::ToCredit);
    $this->travel(1)->days();
    app(SettledRefundCheck::class)->run();

    expect($payment->fresh()->refundable_remaining)->toBe(0)
        ->and($attempt->fresh()->review_reason)->toContain('Refund account credit')->not->toContain('on the invoice');
});

test('RR-E: a credit-kept payment whose portal refund is already recorded with Refund account credit is not flagged', function () {
    [$attempt, $payment] = probeSettledKhalti(paidFirst: true);
    app(RefundAccountCredit::class)->recorded(Client::query()->findOrFail($payment->client_id), 150_000, 'khalti', 'KHALTI-PORTAL-1', 'Refunded in Khalti portal', null);
    $this->travel(1)->days();
    app(SettledRefundCheck::class)->run();

    expect($attempt->fresh()->check_reason)->toBeNull();
});

test('RR-E: a recorded payout does not cover the payment while the client still holds its credit', function () {
    [$attempt, $payment] = probeSettledKhalti(paidFirst: true);
    // Other credit, paid out on its own: the client still holds the Rs 1,500 this payment left.
    app(CreditLedgerService::class)->post($payment->client_id, Money::of(50_000, 'NPR'), CreditReason::Manual, null, null, 'Goodwill');
    app(RefundAccountCredit::class)->recorded(Client::query()->findOrFail($payment->client_id), 50_000, 'khalti', 'KHALTI-PORTAL-2', 'Goodwill paid out', null);
    $this->travel(1)->days();
    app(SettledRefundCheck::class)->run();

    expect($attempt->fresh()->check_reason)->toBe(PaymentAttempt::CHECK_PORTAL_REFUND);
});

test('RR-F: a long Devanagari previous reason is cut on a character boundary, the instruction kept whole', function () {
    $long = str_repeat('भुक्तानी खातामा राखियो ', 20);
    [$attempt] = probeSettledKhalti(['check_reason' => PaymentAttempt::CHECK_LATE, 'review_reason' => mb_substr($long, 0, 191)]);
    $this->answer = 'Partially refunded';
    $this->travel(1)->days();
    app(SettledRefundCheck::class)->run();

    $fresh = $attempt->fresh();
    expect(mb_check_encoding($fresh->review_reason, 'UTF-8'))->toBeTrue()
        ->and(mb_strlen($fresh->review_reason))->toBeLessThanOrEqual(191)
        ->and($fresh->review_reason)->toStartWith('Partly refunded at the provider, but no refund recorded here: record the refund on the invoice; was: ');
});
