<?php

use App\Modules\Billing\Actions\RefundTransaction;
use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Enums\RefundMode;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Billing\Services\SettledRefundCheck;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Client;
use App\Modules\PaymentsNepal\Drivers\EsewaDriver;
use App\Modules\PaymentsNepal\Drivers\KhaltiDriver;
use App\Support\Dashboard\Dashboard;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

require_once __DIR__.'/helpers.php';

/*
| The daily re-lookup of settled Khalti and eSewa payments for refunds made in the provider's portal (#27).
*/

beforeEach(function () {
    Http::preventStrayRequests();
    Sleep::fake();
    nepalGateway(KhaltiDriver::KEY, ['sandbox' => '1', 'secret_key' => 'test_secret_refund_check']);
    // What the Khalti fake answers: the lookup status (or "down" for HTTP 503) and the refund API's HTTP status.
    $this->khaltiAnswer = 'Completed';
    $this->khaltiRefundStatus = 200;
    $this->khaltiSessions = 0;
    Http::fake([
        'https://dev.khalti.com/api/v2/epayment/initiate/' => function () {
            $pidx = 'PIDXRC'.(++$this->khaltiSessions);

            return Http::response(['pidx' => $pidx, 'payment_url' => 'https://test-pay.khalti.com/?pidx='.$pidx, 'expires_in' => 3600]);
        },
        'https://dev.khalti.com/api/v2/epayment/lookup/' => fn (Request $request) => $this->khaltiAnswer === 'down'
            ? Http::response(['detail' => 'Service unavailable.'], 503)
            : Http::response(['pidx' => $request['pidx'], 'total_amount' => 150_000, 'status' => $this->khaltiAnswer, 'transaction_id' => 'TXN-'.$request['pidx'], 'fee' => 300, 'refunded' => $this->khaltiAnswer !== 'Completed']),
        'https://dev.khalti.com/api/merchant-transaction/*' => fn () => $this->khaltiRefundStatus === 200
            ? Http::response(['detail' => 'Transaction refund successful.'])
            : Http::response(['detail' => 'Mobile number is required.'], $this->khaltiRefundStatus),
    ]);
});

/**
 * A Khalti payment of Rs 1,500 started and settled through the real checkout and return (for a new client, or
 * another invoice of the signed-in $client).
 *
 * @return array{0: Invoice, 1: PaymentAttempt, 2: Transaction}
 */
function settledKhaltiForRefundCheck(?Client $client = null): array
{
    $invoice = $client === null ? nprInvoice([150_000])[1] : issueInvoice($client, [150_000]);
    test()->post(route('client.invoices.pay', $invoice->id), ['gateway' => KhaltiDriver::KEY])->assertRedirect();
    $attempt = PaymentAttempt::query()->where('invoice_id', $invoice->id)->sole();
    test()->get(route('billing.gateways.return', [KhaltiDriver::KEY, 'pidx' => $attempt->provider_ref, 'purchase_order_id' => $attempt->reference]));

    expect($attempt->fresh()->status)->toBe(AttemptStatus::Succeeded);

    return [$invoice->fresh(), $attempt->fresh(), Transaction::query()->where('invoice_id', $invoice->id)->where('type', 'payment')->sole()];
}

function refundCheckLookups(): int
{
    return Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/epayment/lookup/'))->count();
}

function portalRefundTodo(): int
{
    return collect(app(Dashboard::class)->resolveTodos('admin', billingStaff()))->firstWhere('key', 'billing.portal_refunds')['count'] ?? 0;
}

test('a full refund made in the Khalti portal is flagged for staff once and moves no money', function () {
    [$invoice, $attempt, $payment] = settledKhaltiForRefundCheck();
    $this->khaltiAnswer = 'Refunded';
    $this->travel(1)->days();

    expect(app(SettledRefundCheck::class)->run())->toBe(1);

    $flagged = $attempt->fresh();
    expect($flagged)->status->toBe(AttemptStatus::Succeeded)->check_reason->toBe(PaymentAttempt::CHECK_PORTAL_REFUND)
        ->and($flagged->review_reason)->toContain('Refunded in full at the provider')
        ->and($flagged->raw[SettledRefundCheck::RAW_KEY])->toMatchArray(['status' => 'refunded', 'refunded_here' => 0])
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and(Transaction::query()->count())->toBe(1)
        ->and($payment->fresh()->refundable_remaining)->toBe(150_000)
        ->and(ActivityLog::query()->where('event', 'billing.payment.portal_refund')->count())->toBe(1)
        ->and(portalRefundTodo())->toBe(1);

    // A re-run the same day looks nothing up; the next day's answer is the same status, so it is not flagged again.
    $lookups = refundCheckLookups();
    expect(app(SettledRefundCheck::class)->run())->toBe(0)->and(refundCheckLookups())->toBe($lookups);

    billingStaff();
    confirmPassword();
    $this->post(route('admin.billing.payment-attempts.late-checked', $attempt->id))->assertRedirect();
    $this->travel(1)->days();
    app(SettledRefundCheck::class)->run();

    expect(ActivityLog::query()->where('event', 'billing.payment.portal_refund')->count())->toBe(1)
        ->and(portalRefundTodo())->toBe(0);

    $this->get(route('admin.billing.payment-attempts.index', ['check' => PaymentAttempt::CHECK_PORTAL_REFUND]))->assertOk()
        ->assertInertia(fn ($page) => $page->has('attempts.data', 1)->where('attempts.data.0.check_reason', PaymentAttempt::CHECK_PORTAL_REFUND));
});

test('a refund Billing made or recorded is not flagged when the provider reports it', function (RefundMode $mode) {
    [, $attempt, $payment] = settledKhaltiForRefundCheck();
    app(RefundTransaction::class)->handle($payment, 150_000, $mode);
    $this->khaltiAnswer = 'Refunded';
    $this->travel(1)->days();

    expect(app(SettledRefundCheck::class)->run())->toBe(1)
        ->and($attempt->fresh()->check_reason)->toBeNull()
        ->and(portalRefundTodo())->toBe(0);
})->with([
    'through the Khalti API' => [RefundMode::Gateway],
    'recorded as made outside' => [RefundMode::RecordOnly],
]);

test('a partial portal refund is flagged, and flagged again when it becomes a full refund', function () {
    [, $attempt] = settledKhaltiForRefundCheck();
    $this->khaltiAnswer = 'Partially refunded';
    $this->travel(1)->days();
    app(SettledRefundCheck::class)->run();

    expect($attempt->fresh()->review_reason)->toContain('Partly refunded');
    billingStaff();
    confirmPassword();
    $this->post(route('admin.billing.payment-attempts.late-checked', $attempt->id))->assertRedirect();
    expect(portalRefundTodo())->toBe(0);

    $this->khaltiAnswer = 'Refunded';
    $this->travel(1)->days();
    app(SettledRefundCheck::class)->run();

    expect($attempt->fresh())->late_checked_at->toBeNull()->review_reason->toContain('Refunded in full')
        ->and(portalRefundTodo())->toBe(1);
});

test('only recent payments at gateways without webhooks are looked up, and the window can be turned off', function () {
    [, $attempt] = settledKhaltiForRefundCheck();

    expect(app(SettledRefundCheck::class)->checkedGateways())->toContain(KhaltiDriver::KEY, EsewaDriver::KEY)
        ->not->toContain('sandbox_card', 'stripe');

    billingSettings(['gateways.refund_check_days' => 0]);
    $this->travel(1)->days();
    expect(app(SettledRefundCheck::class)->run())->toBe(0);

    billingSettings(['gateways.refund_check_days' => 7]);
    $this->travel(7)->days();
    expect(app(SettledRefundCheck::class)->run())->toBe(0)->and($attempt->fresh()->lookup_count)->toBe($attempt->lookup_count);
});

test('a transient Khalti error stops the run for Khalti and leaves the attempt first in line', function () {
    [$invoice, $first] = settledKhaltiForRefundCheck();
    [, $second] = settledKhaltiForRefundCheck($invoice->client);
    $this->khaltiAnswer = 'down';
    $this->travel(1)->days();

    expect(app(SettledRefundCheck::class)->run())->toBe(1)
        ->and($first->fresh()->lookup_count + $second->fresh()->lookup_count)->toBe($first->lookup_count + $second->lookup_count);

    $this->khaltiAnswer = 'Refunded';
    expect(app(SettledRefundCheck::class)->run())->toBe(2)
        ->and(PaymentAttempt::toCheck([PaymentAttempt::CHECK_PORTAL_REFUND])->count())->toBe(2);
});

test('an eSewa FULL_REFUND on a settled payment is flagged through the daily automation task', function () {
    nepalGateway(EsewaDriver::KEY, ['sandbox' => '1', 'product_code' => 'EPAYTEST', 'secret_key' => 'fake-esewa-secret-rc']);
    [$client, $invoice] = nprInvoice([113_050]);
    $payment = payInvoice($invoice, 113_050, '0007RC1', EsewaDriver::KEY);
    $attempt = PaymentAttempt::query()->create([
        'reference' => PaymentAttempt::newReference(), 'gateway' => EsewaDriver::KEY, 'client_id' => $client->id, 'invoice_id' => $invoice->id,
        'amount' => 113_050, 'currency' => 'NPR', 'document_amount' => 113_050, 'document_currency' => 'NPR', 'status' => AttemptStatus::Succeeded,
        'provider_txn_id' => '0007RC1', 'transaction_id' => $payment->id, 'last_lookup_at' => now(), 'lookup_count' => 1,
    ]);
    Http::fake(['https://rc.esewa.com.np/api/epay/transaction/status/*' => Http::response([
        'product_code' => 'EPAYTEST', 'transaction_uuid' => $attempt->reference, 'total_amount' => 1130.5, 'status' => 'FULL_REFUND', 'ref_id' => '0007RC1',
    ])]);
    $this->travel(1)->days();

    $this->artisan('automation:run', ['--only' => 'billing.gateway_refund_check'])->assertSuccessful();

    expect($attempt->fresh()->check_reason)->toBe(PaymentAttempt::CHECK_PORTAL_REFUND)
        ->and(Http::recorded(fn (Request $request) => str_contains($request->url(), 'transaction_uuid='.$attempt->reference) && str_contains($request->url(), 'total_amount=1130.50'))->count())->toBe(1);
});

test('a refund Khalti declines tells staff to refund a bank-funded payment in the Khalti portal', function () {
    [, , $payment] = settledKhaltiForRefundCheck();
    $this->khaltiRefundStatus = 400;

    expect(fn () => app(RefundTransaction::class)->handle($payment, 150_000, RefundMode::Gateway))
        ->toThrow(BillingException::class, KhaltiDriver::BANK_FUNDED_NOTE);
});
