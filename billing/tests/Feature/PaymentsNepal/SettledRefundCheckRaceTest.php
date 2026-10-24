<?php

use App\Models\User;
use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Enums\TransactionStatus;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Billing\Services\SettledRefundCheck;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\PaymentsNepal\Drivers\KhaltiDriver;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../Billing/Support/race.php';

/*
| Race tests for the settled-payment refund check (#27): the check applying Khalti's "Refunded" against a staff refund of
| the same payment, in two processes on committed data, so PostgreSQL.
*/

beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Needs separate committed sessions on one server database (PostgreSQL).');
    }
});

/**
 * Two settled Khalti payments of Rs 1,500, one per race.
 *
 * @return array{record: array{attempt: int, payment: int}, refund: array{attempt: int, payment: int}}
 */
function seedRefundCheckRaces(): array
{
    test()->useSeededDatabase('settled_refund_check_races', function (): void {
        Currency::factory()->create();
        Currency::factory()->npr()->create();
        nepalGateway(KhaltiDriver::KEY, ['sandbox' => '1', 'secret_key' => 'test_secret_refund_race']);
        User::factory()->staff(['billing.*'])->create();
        $client = Client::factory()->create(['country' => 'NP', 'currency' => 'NPR', 'email' => 'refund-check-races@example.test']);

        foreach (['RACE1', 'RACE2'] as $pidx) {
            $invoice = issueInvoice($client, [150_000]);
            $payment = payInvoice($invoice, 150_000, 'TXN-'.$pidx, KhaltiDriver::KEY);
            PaymentAttempt::query()->create([
                'reference' => PaymentAttempt::newReference(), 'gateway' => KhaltiDriver::KEY, 'client_id' => $client->id, 'invoice_id' => $invoice->id,
                'amount' => 150_000, 'currency' => 'NPR', 'document_amount' => 150_000, 'document_currency' => 'NPR', 'status' => AttemptStatus::Succeeded,
                'provider_ref' => $pidx, 'provider_txn_id' => 'TXN-'.$pidx, 'transaction_id' => $payment->id, 'last_lookup_at' => now()->subDay(), 'lookup_count' => 1,
            ]);
        }
    });

    $attempts = PaymentAttempt::query()->where('gateway', KhaltiDriver::KEY)->orderBy('id')->get(['id', 'transaction_id']);

    return [
        'record' => ['attempt' => $attempts[0]->id, 'payment' => $attempts[0]->transaction_id],
        'refund' => ['attempt' => $attempts[1]->id, 'payment' => $attempts[1]->transaction_id],
    ];
}

test('the check and a staff refund of the same payment end as one serial order: the flag never stands beside a known refund', function (string $refund) {
    $ids = seedRefundCheckRaces()[$refund];
    $start = (string) ((int) (microtime(true) * 1000) + 2_500);
    $database = DB::connection()->getDatabaseName();

    $results = runRaceWorkers([
        'check' => ['check', $database, (string) $ids['attempt'], $start],
        'staff' => [$refund, $database, (string) $ids['payment'], $start],
    ], script: __DIR__.'/Support/refund-check-worker.php');
    $attempt = PaymentAttempt::query()->findOrFail($ids['attempt']);

    expect($results['check']['outcome'])->toBe('checked')
        ->and($results['staff']['outcome'])->toBe('refunded')
        ->and($attempt->status)->toBe(AttemptStatus::Succeeded)
        ->and($attempt->last_lookup_at->isToday())->toBeTrue()
        // The staff refund is the only money that moved, and only once.
        ->and(Transaction::query()->where('parent_transaction_id', $ids['payment'])->where('type', 'refund')->get(['status', 'amount_out'])->toArray())
        ->toBe([['status' => TransactionStatus::Succeeded->value, 'amount_out' => 150_000]])
        ->and(Transaction::query()->findOrFail($ids['payment'])->refundable_remaining)->toBe(0);

    // Flagged only when the check read the payment before the refund committed: it then saw nothing refunded here.
    if ($attempt->check_reason !== null) {
        expect($attempt->check_reason)->toBe(PaymentAttempt::CHECK_PORTAL_REFUND)
            ->and($attempt->raw[SettledRefundCheck::RAW_KEY]['refunded_here'])->toBe(0);
    }
})->with([
    'recorded as made outside' => ['record'],
    'through the Khalti API' => ['refund'],
]);
