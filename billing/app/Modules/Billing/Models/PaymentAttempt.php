<?php

namespace App\Modules\Billing\Models;

use App\Data\Billing\PaymentAttemptRef;
use App\Modules\Billing\Enums\AttemptStatus;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One hosted-payment try (Khalti/eSewa/… or sandbox hosted). amount/currency is what the provider collects;
 * document_amount/document_currency is what it pays on the invoice or batch.
 *
 * @property int $id
 * @property string $reference
 * @property string $gateway
 * @property int $client_id
 * @property int|null $invoice_id
 * @property int|null $payment_batch_id
 * @property int $amount
 * @property string $currency
 * @property int $document_amount
 * @property string $document_currency
 * @property string|null $fx_rate
 * @property string|null $provider_ref
 * @property string|null $provider_txn_id
 * @property AttemptStatus $status
 * @property string|null $review_reason
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $last_lookup_at
 * @property int $lookup_count
 * @property int|null $transaction_id
 * @property array<string, mixed>|null $raw
 * @property CarbonImmutable|null $late_payment_at
 * @property string|null $check_reason
 * @property CarbonImmutable|null $late_checked_at
 * @property CarbonImmutable|null $lookups_closed_at
 * @property string|null $resume_url
 * @property CarbonImmutable|null $created_at
 * @property-read Invoice|null $invoice
 */
#[Fillable([
    'reference', 'gateway', 'client_id', 'invoice_id', 'payment_batch_id', 'amount', 'currency', 'document_amount',
    'document_currency', 'fx_rate', 'provider_ref', 'provider_txn_id', 'status', 'review_reason', 'expires_at',
    'last_lookup_at', 'lookup_count', 'transaction_id', 'raw', 'late_payment_at', 'check_reason',
    'late_checked_at', 'lookups_closed_at', 'resume_url',
])]
class PaymentAttempt extends Model
{
    /** Booked after the attempt closed (#130). */
    public const CHECK_LATE = 'late';

    /** Booked on time, but all or part went to account credit: invoice already paid or cancelled, or payment held. */
    public const CHECK_CREDIT = 'credit';

    /** Refunded at the provider for more than the account credit left from it. */
    public const CHECK_REFUND = 'refund';

    /** Refunded in the provider's portal after it was booked, found by the daily re-lookup (SettledRefundCheck). */
    public const CHECK_PORTAL_REFUND = 'portal_refund';

    /** Staff can mark checked: shown on the "Late payments to check" or "Gateway payments to review" to-do. */
    public function needsCheck(): bool
    {
        return $this->check_reason !== null && $this->late_checked_at === null && $this->status === AttemptStatus::Succeeded;
    }

    /**
     * Unchecked rows of the given check reasons (the to-do counts).
     *
     * @param  list<string>  $reasons
     * @return Builder<self>
     */
    public static function toCheck(array $reasons): Builder
    {
        return self::query()->whereIn('check_reason', $reasons)->whereNull('late_checked_at')->where('status', AttemptStatus::Succeeded);
    }

    protected $attributes = ['status' => 'pending', 'lookup_count' => 0];

    /** The kept provider page (#251) is for the driver only: never in an array, JSON or page prop. */
    protected $hidden = ['resume_url'];

    protected function casts(): array
    {
        return [
            'status' => AttemptStatus::class,
            'amount' => 'integer',
            'document_amount' => 'integer',
            'fx_rate' => 'decimal:12',
            'expires_at' => 'datetime',
            'last_lookup_at' => 'datetime',
            'late_payment_at' => 'datetime',
            'late_checked_at' => 'datetime',
            'lookups_closed_at' => 'datetime',
            'lookup_count' => 'integer',
            'raw' => 'array',
            'resume_url' => 'encrypted',
        ];
    }

    /**
     * Unguessable attempt reference: 26 base32 characters ([a-z2-7], 130 bits of randomness). Public return and
     * sandbox URLs carry it, so it must not be enumerable. Drivers whose provider caps reference length map
     * their own id through provider_ref.
     */
    public static function newReference(): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyz234567';
        $reference = '';

        foreach (str_split(random_bytes(26)) as $byte) {
            $reference .= $alphabet[ord($byte) & 31];
        }

        return $reference;
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function money(): Money
    {
        return Money::of($this->amount, $this->currency);
    }

    /**
     * @param  bool  $withResumeUrl  decrypt and include the kept provider page (#251): ResumeHostedPayment only, so
     *                               lookup(), cancelSession() and every other driver call never receive it
     */
    public function toRef(bool $interactive = false, bool $withResumeUrl = false): PaymentAttemptRef
    {
        return new PaymentAttemptRef($this->reference, $this->money(), $this->provider_ref, $this->provider_txn_id, $this->expires_at, $interactive, $withResumeUrl ? $this->resume_url : null);
    }
}
