<?php

namespace App\Modules\Billing\Services;

use App\Contracts\Billing\GatewayDriver;
use App\Contracts\Billing\HostedPaymentDriver;
use App\Data\Billing\LookupResult;
use App\Modules\Billing\Drivers\Gateways\GatewayManager;
use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Enums\CreditReason;
use App\Modules\Billing\Enums\TransactionStatus;
use App\Modules\Billing\Enums\TransactionType;
use App\Modules\Billing\Models\CreditLedgerEntry;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Billing\Support\BillingSettings;
use App\Modules\Core\Support\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Daily re-lookup of settled hosted payments at providers that push no webhook (Khalti, eSewa; #27): a refund made in
 * the provider's portal is otherwise never seen. A refund found there moves no money. The attempt is flagged
 * (`check_reason` portal_refund, the "Refunds made at the provider" to-do); staff record the refund on the invoice
 * through the usual refund dialog and mark the payment checked. A refund Billing already holds for the payment (booked,
 * or reserved while the provider answers) is not flagged.
 *
 * Bounded per run: `gateways.refund_checks_per_run` lookups, a deadline, and a per-gateway stop after a transient error.
 * Each attempt is looked up at most once per INTERVAL_HOURS, so a re-run of the day's automation task looks nothing up again.
 */
class SettledRefundCheck
{
    /** Upper bound for `gateways.refund_check_days`. */
    public const MAX_DAYS = 30;

    /** A settled attempt is looked up again only this long after its last lookup. */
    public const INTERVAL_HOURS = 20;

    /** The run stops starting lookups after this many seconds. */
    public const DEADLINE_SECONDS = 600;

    /** Key in the attempt's raw: the provider refund status last flagged, and when. */
    public const RAW_KEY = 'portal_refund';

    public const NOTE = 'Refund made at the provider found for a settled payment: record it on the invoice';

    public function __construct(
        private GatewayManager $gateways,
        private GatewayLogger $log,
        private BillingSettings $settings,
        private ActivityLogger $activity,
        private CreditLedgerService $credit,
    ) {}

    /**
     * @return int lookups made
     */
    public function run(): int
    {
        $days = min(self::MAX_DAYS, max(0, $this->settings->int('gateways.refund_check_days', 7)));
        $gateways = $this->checkedGateways();

        if ($days === 0 || $gateways === []) {
            return 0;
        }

        $cap = min(PaymentReconciler::MAX_LOOKUPS_PER_RUN, max(1, $this->settings->int('gateways.refund_checks_per_run', 300)));
        $start = now()->toImmutable();
        $deadline = microtime(true) + self::DEADLINE_SECONDS;
        /** @var array<string, true> $tripped gateways that gave a transient error in this run */
        $tripped = [];
        $checked = 0;

        // Served by payment_attempts_created_at_index: only attempts started inside the window are read, so the work
        // grows with the payments of the window, not with the table. Least recently looked up first; the rest wait a day.
        $attempts = PaymentAttempt::query()
            ->where('created_at', '>=', $start->subDays($days))
            ->where('status', AttemptStatus::Succeeded)
            ->whereIn('gateway', $gateways)
            ->whereNotNull('transaction_id')
            ->where(fn ($query) => $query->whereNull('last_lookup_at')->orWhere('last_lookup_at', '<=', $start->subHours(self::INTERVAL_HOURS)))
            ->orderByRaw('case when last_lookup_at is null then 0 else 1 end')
            ->orderBy('last_lookup_at')
            ->orderBy('id')
            ->limit($cap)
            ->get();

        foreach ($attempts as $attempt) {
            if (microtime(true) >= $deadline) {
                break;
            }

            if (isset($tripped[$attempt->gateway]) || ($driver = $this->gateways->hosted($attempt->gateway)) === null) {
                continue;
            }

            try {
                $config = $this->gateways->config($attempt->gateway);
                $result = $this->gateways->during($config, fn (): LookupResult => $driver->lookup($config, $attempt->toRef()));
                $checked++;
                $this->log->log($attempt->gateway, 'response', 'settled lookup '.$result->status, ['reference' => $attempt->reference, ...GatewayLogger::redact($result->raw, $attempt->gateway)], $attempt->invoice_id);

                // Not stamped: the attempt is first in line again on the next run.
                if ($result->retryable) {
                    $tripped[$attempt->gateway] = true;

                    continue;
                }

                $this->applyAnswer($attempt, $result);
            } catch (Throwable $exception) {
                $tripped[$attempt->gateway] = true;
                report($exception);
            }
        }

        return $checked;
    }

    /**
     * Gateways whose driver can be asked about a payment but pushes no webhook, so a refund is seen only by asking.
     *
     * @return list<string>
     */
    public function checkedGateways(): array
    {
        return array_keys(array_filter(
            $this->gateways->drivers(),
            fn (GatewayDriver $driver): bool => $driver instanceof HostedPaymentDriver && $driver->supports(GatewayDriver::LOOKUP) && ! $driver->supports(GatewayDriver::WEBHOOK),
        ));
    }

    /**
     * Applies one answer under the payment's lock, then the attempt's (the provider refund callback's order), so a staff
     * refund of the same payment, which reserves under the payment's lock, is either seen here or comes after the flag.
     * Flags only a refund the payment's own refund rows (succeeded or pending) do not account for: a full refund while
     * they total less than the payment, or a partial one while there are none. A partial refund after one recorded here
     * is not told apart (neither provider reports the refunded amount). The same status is flagged once; a partial
     * refund that later becomes full is flagged again.
     *
     * @internal public for the race tests, which apply an answer looked up beforehand; run() is the only caller in the app
     */
    public function applyAnswer(PaymentAttempt $attempt, LookupResult $result): void
    {
        DB::transaction(function () use ($attempt, $result): void {
            $payment = Transaction::query()->lockForUpdate()->find($attempt->transaction_id);
            $locked = PaymentAttempt::query()->lockForUpdate()->find($attempt->id);

            if ($payment === null || $locked === null || $locked->status !== AttemptStatus::Succeeded || $locked->transaction_id !== $payment->id) {
                return;
            }

            $locked->last_lookup_at = now();
            $locked->lookup_count++;
            $full = $result->status === LookupResult::REFUNDED;
            $credit = $this->creditPart($payment);
            $known = $this->refundedAtProvider($payment) + $this->recordedFromCredit($payment, $credit);
            $uncovered = $full ? $known < $payment->amount_in : $known === 0;
            $flagged = $locked->raw[self::RAW_KEY]['status'] ?? null;

            if (! in_array($result->status, [LookupResult::REFUNDED, LookupResult::PARTIALLY_REFUNDED], true) || ! $uncovered || $flagged === $result->status) {
                $locked->save();

                return;
            }

            // An open late or credit flag is kept beside this one: its reason stays in the review reason and in raw.
            $previous = $locked->needsCheck() && $locked->check_reason !== PaymentAttempt::CHECK_PORTAL_REFUND
                ? ['check_reason' => $locked->check_reason, 'review_reason' => $locked->review_reason]
                : ($locked->raw[self::RAW_KEY]['previous_check'] ?? null);
            $was = is_array($previous) && is_string($previous['review_reason'] ?? null) ? '; was: '.$previous['review_reason'] : '';

            $locked->check_reason = PaymentAttempt::CHECK_PORTAL_REFUND;
            $locked->late_checked_at = null;
            $locked->review_reason = mb_substr(($full ? 'Refunded in full at the provider, but not recorded here: ' : 'Partly refunded at the provider, but no refund recorded here: ')
                .self::instruction($credit, $payment->refundable_remaining).$was, 0, 191);
            $locked->raw = [...($locked->raw ?? []), self::RAW_KEY => array_filter([
                'status' => $result->status,
                'seen_at' => now()->toIso8601String(),
                'refunded_here' => $known,
                'credited' => $credit,
                'previous_check' => $previous,
            ], fn (mixed $value): bool => $value !== null)];
            $locked->save();

            $this->activity->log(self::NOTE, $payment, $locked->client_id, [
                'reference' => $locked->reference,
                'gateway' => $locked->gateway,
                'provider_txn_id' => $locked->provider_txn_id,
                'provider_status' => $result->status,
                'amount' => $payment->amount_in,
                'refunded_here' => $known,
                'credited' => $credit,
                'previous_check' => $previous['check_reason'] ?? null,
                'currency' => $payment->currency,
            ], 'billing.payment.portal_refund');
        });
    }

    /**
     * What this payment's refund rows (succeeded or pending) paid back through the provider or recorded as paid outside.
     * A refund to account credit is left out: the money stayed with the client as credit, so a portal refund on top of
     * it pays the client twice. Such a row is never pending, and its refund_to_credit ledger line is written in the same
     * transaction (RefundTransaction), so the ledger tells it apart for old rows too.
     */
    private function refundedAtProvider(Transaction $payment): int
    {
        return (int) Transaction::query()->where('parent_transaction_id', $payment->id)->where('type', TransactionType::Refund)
            ->whereIn('status', [TransactionStatus::Succeeded, TransactionStatus::Pending])
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('credit_ledger')
                ->whereColumn('credit_ledger.transaction_id', 'transactions.id')->where('credit_ledger.reason', CreditReason::RefundToCredit->value))
            ->sum('amount_out');
    }

    /**
     * The account credit this payment placed (an overpayment, or its own refunds to credit), less the credit payouts
     * made through the gateway from it, which refundedAtProvider() already counts.
     */
    private function creditPart(Transaction $payment): int
    {
        $refunds = Transaction::query()->where('parent_transaction_id', $payment->id)->where('type', TransactionType::Refund)->where('status', TransactionStatus::Succeeded)->select('id');
        $placed = (int) CreditLedgerEntry::query()->where('currency', $payment->currency)->where('amount', '>', 0)
            ->where(fn ($lines) => $lines->where(fn ($own) => $own->where('transaction_id', $payment->id)->where('reason', CreditReason::Overpayment))
                ->orWhere(fn ($refunded) => $refunded->whereIn('transaction_id', $refunds)->where('reason', CreditReason::RefundToCredit)))
            ->sum('amount');

        return max(0, $placed - Transaction::creditPaidOut([$payment->id], $payment->currency));
    }

    /**
     * The part of $credit that staff already recorded as paid out with Refund account credit (recorded). That payout
     * names no payment, so it counts here only up to what the client no longer holds: payouts recorded since the
     * payment, and at most $credit less the client's credit balance now. A client still holding the credit is flagged.
     */
    private function recordedFromCredit(Transaction $payment, int $credit): int
    {
        if ($credit <= 0) {
            return 0;
        }

        $recorded = (int) Transaction::query()->where('client_id', $payment->client_id)->where('purpose', Transaction::PURPOSE_CREDIT_REFUND)
            ->whereNull('parent_transaction_id')->where('status', TransactionStatus::Succeeded)->where('currency', $payment->currency)
            ->where('created_at', '>=', $payment->created_at)->sum('amount_out');
        $held = $this->credit->balance($payment->client_id, $payment->currency)->amount;

        return max(0, min($credit, $recorded, $credit - $held));
    }

    /**
     * Where staff record the refund: on the invoice for what the payment can still refund there, with Refund account
     * credit for the part it left (or a refund turned) into account credit, which the invoice's refund refuses.
     */
    private static function instruction(int $credit, int $refundable): string
    {
        return match (true) {
            $credit <= 0 => 'record the refund on the invoice',
            $refundable <= 0 => 'the payment went to account credit, so record it with Refund account credit',
            default => 'record it on the invoice, and the part kept as account credit with Refund account credit',
        };
    }
}
