<?php

/**
 * Race helper (#27): the settled-payment refund check, or a staff refund of the same Khalti payment, in its own process,
 * started at a common instant so the two meet on the payment and attempt locks. Started by runRaceWorkers().
 *
 *   php refund-check-worker.php <label> check <database> <attempt id> <start at ms>    Khalti answers Refunded; the check applies it
 *   php refund-check-worker.php <label> record <database> <payment id> <start at ms>   staff record a full refund made outside
 *   php refund-check-worker.php <label> refund <database> <payment id> <start at ms>   staff refund in full through the Khalti API
 *
 * Prints {label, database, transaction_level, outcome, ...}.
 */

use App\Data\Billing\LookupResult;
use App\Models\User;
use App\Modules\Billing\Actions\RefundTransaction;
use App\Modules\Billing\Drivers\Gateways\GatewayManager;
use App\Modules\Billing\Enums\RefundMode;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Billing\Services\SettledRefundCheck;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

[, $label, $mode, $database, $id, $startAt] = $argv;
$base = dirname(__DIR__, 4);

putenv("DB_DATABASE=$database");
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = $database;

require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$print = fn (array $result) => fwrite(STDOUT, (string) json_encode(['label' => $label, 'database' => $database, 'transaction_level' => DB::transactionLevel(), ...$result]));
$wait = fn () => usleep((int) max(0, ((int) $startAt - (int) (microtime(true) * 1000)) * 1000));

Http::preventStrayRequests();
Http::fake([
    'https://dev.khalti.com/api/v2/epayment/lookup/' => fn (Request $request) => Http::response(['pidx' => $request['pidx'], 'total_amount' => 150_000, 'status' => 'Refunded', 'transaction_id' => 'TXN-'.$request['pidx'], 'fee' => 300, 'refunded' => true]),
    'https://dev.khalti.com/api/merchant-transaction/*' => Http::response(['detail' => 'Transaction refund successful.']),
]);

try {
    switch ($mode) {
        case 'check':
            // The provider call is made outside any lock, as in run(); only applying its answer races.
            $attempt = PaymentAttempt::query()->findOrFail((int) $id);
            $gateways = app(GatewayManager::class);
            $config = $gateways->config($attempt->gateway);
            $driver = $gateways->hosted($attempt->gateway);
            $result = $gateways->during($config, fn (): LookupResult => $driver->lookup($config, $attempt->toRef()));
            $check = app(SettledRefundCheck::class);
            $wait();
            $check->applyAnswer($attempt, $result);
            $print(['outcome' => 'checked', 'status' => $result->status]);
            break;
        default:
            $payment = Transaction::query()->findOrFail((int) $id);
            $wait();
            app(RefundTransaction::class)->handle($payment, 150_000, $mode === 'record' ? RefundMode::RecordOnly : RefundMode::Gateway, false, User::query()->where('is_staff', true)->value('id'), reason: 'Race '.$label);
            $print(['outcome' => 'refunded']);
    }
} catch (Throwable $e) {
    $print(['outcome' => class_basename($e), 'message' => $e->getMessage()]);
}
