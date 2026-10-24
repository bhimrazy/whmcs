<?php

namespace App\Modules\Billing\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Actions\SettlePaymentAttempt;
use App\Modules\Billing\Drivers\Gateways\GatewayManager;
use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\Core\Support\ActivityLogger;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Hosted payment attempts: what the poller is watching and what needs staff review.
 */
class PaymentAttemptController extends Controller
{
    public function index(Request $request, GatewayManager $gateways): Response
    {
        $this->authorize('billing.payments.manage');
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(AttemptStatus::class)],
            'gateway' => ['nullable', 'string', 'max:50'],
            'late' => ['nullable', 'boolean'],
            'review' => ['nullable', 'boolean'],
            'check' => ['nullable', Rule::in([PaymentAttempt::CHECK_LATE, PaymentAttempt::CHECK_CREDIT, PaymentAttempt::CHECK_REFUND, PaymentAttempt::CHECK_PORTAL_REFUND])],
        ]);

        return Inertia::render('admin/billing/payment-attempts/index', [
            'attempts' => PaymentAttempt::query()
                ->with('invoice:id,number,proforma_number')
                ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
                ->when($filters['gateway'] ?? null, fn ($query, $gateway) => $query->where('gateway', $gateway))
                // The "Gateway payments to review" to-do: attempts in review and booked payments refunded beyond their credit.
                ->when((bool) ($filters['review'] ?? false), fn ($query) => $query->where(fn ($query) => $query->where('status', AttemptStatus::Ambiguous)
                    ->orWhere(fn ($query) => $query->where('check_reason', PaymentAttempt::CHECK_REFUND)->whereNull('late_checked_at')->where('status', AttemptStatus::Succeeded))))
                ->when($filters['check'] ?? null, fn ($query, $reason) => $query->where('check_reason', $reason))
                ->when((bool) ($filters['late'] ?? false), fn ($query) => $query->where(fn ($query) => $query->whereNotNull('late_payment_at')->orWhereNotNull('check_reason')))
                ->orderByDesc('id')
                ->paginate(25)
                ->withQueryString()
                ->through(fn (PaymentAttempt $attempt): array => [
                    'id' => $attempt->id,
                    'reference' => $attempt->reference,
                    'gateway' => $attempt->gateway,
                    'gateway_name' => $gateways->has($attempt->gateway) ? $gateways->gateway($attempt->gateway)->display_name : $attempt->gateway,
                    'status' => $attempt->status->value,
                    'amount' => $attempt->amount,
                    'currency' => $attempt->currency,
                    'invoice_id' => $attempt->invoice_id,
                    'invoice_number' => $attempt->invoice?->displayNumber(),
                    'provider_txn_id' => $attempt->provider_txn_id,
                    'review_reason' => $attempt->review_reason,
                    'lookup_count' => $attempt->lookup_count,
                    'last_lookup_at' => $attempt->last_lookup_at?->toIso8601String(),
                    'expires_at' => $attempt->expires_at?->toIso8601String(),
                    'created_at' => $attempt->created_at?->toIso8601String(),
                    'late_payment_at' => $attempt->late_payment_at?->toIso8601String(),
                    'late_checked_at' => $attempt->late_checked_at?->toIso8601String(),
                    'check_reason' => $attempt->check_reason,
                    'needs_check' => $attempt->needsCheck(),
                    'can_resolve' => $attempt->status === AttemptStatus::Ambiguous,
                    'staff_closed' => $attempt->review_reason === SettlePaymentAttempt::STAFF_CLOSED_REASON,
                    'return_code' => is_string($attempt->raw[SettlePaymentAttempt::SIGNED_RETURN]['transaction_code'] ?? null) ? $attempt->raw[SettlePaymentAttempt::SIGNED_RETURN]['transaction_code'] : null,
                ]),
            'filters' => (object) array_filter($filters),
            'statuses' => array_map(fn (AttemptStatus $status): array => ['value' => $status->value, 'label' => ucfirst($status->value)], AttemptStatus::cases()),
        ]);
    }

    public function check(PaymentAttempt $attempt, SettlePaymentAttempt $settle): RedirectResponse
    {
        $this->authorize('billing.payments.manage');
        // Staff are waiting on this request: one quick provider try, like the customer's return.
        $status = $settle->handle($attempt, interactive: true);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Attempt :reference is :status.', ['reference' => $attempt->reference, 'status' => $status->value])]);

        return back();
    }

    /**
     * Staff settle an attempt in review by hand, after looking in the provider's portal: it was paid (the amount they
     * read there must match, and the provider's reference is booked once) or it was not (closed for good, the invoice
     * can be paid again). The audit entry is written by the action, inside its transaction.
     */
    public function resolve(Request $request, PaymentAttempt $attempt, SettlePaymentAttempt $settle): RedirectResponse
    {
        $this->authorize('billing.payments.manage');
        $data = $request->validate([
            'outcome' => ['required', 'in:paid,not_paid'],
            'provider_reference' => ['required_if:outcome,paid', 'nullable', 'string', 'max:191'],
            'amount' => ['required_if:outcome,paid', 'nullable', 'string', 'regex:/^\d{1,3}(,?\d{3}){0,3}(\.\d{1,2})?$/'],
        ], ['amount.regex' => __('Enter the amount as the portal shows it, for example 1130.50.')]);
        $paid = $data['outcome'] === 'paid';

        $paid
            ? $settle->resolvePaid($attempt, $data['provider_reference'] ?? '', Money::fromDecimal(str_replace(',', '', $data['amount'] ?? '0'), $attempt->currency)->amount)
            : $settle->resolveNotPaid($attempt);
        Inertia::flash('toast', ['type' => 'success', 'message' => $paid ? __('Payment recorded.') : __('Closed as not paid. The invoice can be paid again.')]);

        return back();
    }

    /**
     * Staff checked a flagged payment (refunded it at the provider, or kept it as credit): clear its to-do. A
     * conditional update, so a double click writes one audit entry.
     */
    public function lateChecked(PaymentAttempt $attempt, ActivityLogger $activity): RedirectResponse
    {
        $this->authorize('billing.payments.manage');
        abort_unless($attempt->check_reason !== null && $attempt->status === AttemptStatus::Succeeded, 404);

        $updated = PaymentAttempt::toCheck([$attempt->check_reason])->whereKey($attempt->id)->update(['late_checked_at' => now()]);

        if ($updated === 1) {
            $activity->log('Flagged gateway payment checked', $attempt, $attempt->client_id, ['reference' => $attempt->reference, 'gateway' => $attempt->gateway, 'check_reason' => $attempt->check_reason], 'billing.payment.late_checked');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Payment :reference marked as checked.', ['reference' => $attempt->reference])]);

        return back();
    }
}
