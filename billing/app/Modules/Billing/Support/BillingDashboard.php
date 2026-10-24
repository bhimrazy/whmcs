<?php

namespace App\Modules\Billing\Support;

use App\Models\User;
use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Enums\TransactionType;
use App\Modules\Billing\Models\CreditLedgerEntry;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Billing\Services\NoteRefusals;
use App\Modules\Core\Support\CoreCompanyClock;
use App\Modules\Core\Support\CurrencyRollup;
use App\Support\Currencies\MoneyFormatter;
use App\Support\Dashboard\Dashboard;
use App\Support\Dashboard\Trend;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Billing's admin dashboard: income / outstanding / overdue KPIs, the income chart (12 months vs. the previous
 * year, or 30 days vs. the previous 30), income by currency with tax collected, recent payments, to-dos and the
 * payment-gateway health check.
 *
 * Every money widget carries a consolidated base payload (all currencies in the default currency at today's rates)
 * plus `variants` per currency (that currency only, unconverted). The figures come from one cached per-currency
 * dataset (see dataset()) so the widgets share three queries.
 */
class BillingDashboard
{
    /** Seconds the dataset is fresh. */
    public const CACHE_SECONDS = 60;

    /**
     * Seconds an older dataset is still served while the request that found it recomputes it after sending its
     * response (Cache::flexible, #441), so the visitor who finds it stale is not the one who waits. A visitor still
     * waits when there is nothing to serve: the first visit of a company day (the key holds the date) or after more
     * than this many idle seconds. So does the stale visitor on a server without fastcgi_finish_request (Apache
     * mod_php, `artisan serve`); FrankenPHP and PHP-FPM send the response first. Widgets carry `asOf`, the time the
     * dataset was computed, so the page can say how old the figures are.
     */
    public const STALE_SECONDS = 600;

    /** Transaction types that make up net income (refunds and chargebacks are negative). */
    private const INCOME_TYPES = [TransactionType::Payment, TransactionType::ManualIncome, TransactionType::Refund, TransactionType::Chargeback];

    /** A currency with no open or recently closed invoices. */
    private const EMPTY_BOOK = [
        'outstanding' => 0, 'outstanding_count' => 0, 'overdue' => 0, 'overdue_count' => 0, 'oldest_due' => null,
        'outstanding_weeks' => [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0], 'outstanding_month_ago' => 0,
        'overdue_weeks' => [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0], 'overdue_month_ago' => 0,
    ];

    /** Weekly points on the KPI sparklines. */
    private const WEEKS = 12;

    public function __construct(private BillingSettings $settings, private CoreCompanyClock $calendar) {}

    /** The company date as a date value (00:00 UTC), like the date columns the widgets compare it with. */
    private function companyDate(): CarbonImmutable
    {
        return $this->calendar->todayDate();
    }

    public function register(Dashboard $dashboard): void
    {
        $dashboard->card('admin', 'billing.income', fn (?User $user): array => $this->incomeCard(), 'billing.transactions.view', 2);
        $dashboard->card('admin', 'billing.unpaid', fn (?User $user): array => $this->outstandingCard(), 'billing.invoices.view', 3);
        $dashboard->card('admin', 'billing.overdue', fn (?User $user): array => $this->overdueCard(), 'billing.invoices.view', 4);

        $dashboard->widget('admin', 'billing.income_chart', 'chart', fn (?User $user): array => $this->incomeChart(), 'billing.transactions.view', 20, 'lg', deferred: true);
        $dashboard->widget('admin', 'billing.currency_split', 'breakdown', fn (?User $user): array => $this->currencySplit(), 'billing.transactions.view', 45, 'md', deferred: true);
        $dashboard->widget('admin', 'billing.recent_payments', 'list', fn (?User $user): array => $this->recentPayments(), 'billing.transactions.view', 51, 'md');

        $dashboard->todo('admin', 'billing.overdue', fn (?User $user): array => $this->overdueTodo(), 'billing.invoices.view', 10);

        $dashboard->todo('admin', 'billing.due_today', fn (?User $user): array => [
            'label' => 'Unpaid invoices due today',
            'count' => Invoice::query()->where('status', InvoiceStatus::Unpaid)->whereDate('due_date', $this->companyDate()->toDateString())->count(),
            'href' => route('admin.billing.invoices.index', ['status' => 'unpaid']),
            'tone' => 'warning',
            'icon' => 'CalendarClock',
        ], 'billing.invoices.view', 11);

        $dashboard->todo('admin', 'billing.failed_captures', fn (?User $user): array => [
            'label' => 'Failed automatic card charges',
            'count' => Invoice::query()->where('status', InvoiceStatus::Unpaid)->whereNotNull('first_capture_failure_on')->count(),
            'href' => route('admin.billing.invoices.index', ['status' => 'unpaid']),
            'tone' => 'danger',
            'icon' => 'CreditCard',
        ], 'billing.invoices.view', 12);

        $dashboard->todo('admin', 'billing.payment_review', fn (?User $user): array => [
            'label' => 'Gateway payments to review',
            'count' => $this->ambiguousAttempts(),
            'href' => route('admin.billing.payment-attempts.index', ['review' => 1]),
            'tone' => 'danger',
            'icon' => 'CreditCard',
        ], 'billing.payments.manage', 13);

        $dashboard->todo('admin', 'billing.late_payments', fn (?User $user): array => [
            'label' => 'Late payments to check',
            'count' => PaymentAttempt::toCheck([PaymentAttempt::CHECK_LATE, PaymentAttempt::CHECK_CREDIT])->count(),
            'href' => route('admin.billing.payment-attempts.index', ['late' => 1]),
            'tone' => 'warning',
            'icon' => 'CreditCard',
            'hint' => 'Paid late, or kept as credit: refund if needed',
        ], 'billing.payments.manage', 14);

        $dashboard->todo('admin', 'billing.portal_refunds', fn (?User $user): array => [
            'label' => 'Refunds made at the provider to record',
            'count' => PaymentAttempt::toCheck([PaymentAttempt::CHECK_PORTAL_REFUND])->count(),
            'href' => route('admin.billing.payment-attempts.index', ['check' => PaymentAttempt::CHECK_PORTAL_REFUND]),
            'tone' => 'danger',
            'icon' => 'CreditCard',
            'hint' => 'Refunded in the gateway\'s portal: record it on the invoice or with Refund account credit, as the reason says, then mark it checked',
        ], 'billing.payments.manage', 14);

        $dashboard->todo('admin', 'billing.reversals_review', fn (?User $user): array => [
            'label' => 'Disputes to review',
            // Also needs the Transactions page it links to (billing.transactions.view), or the link would be refused.
            'count' => $user?->hasPermission('billing.transactions.view') ? Transaction::toReview()->count() : 0,
            'href' => route('admin.billing.transactions.index', ['review' => 1]),
            'tone' => 'danger',
            'icon' => 'AlertTriangle',
            'hint' => 'Inquiries to answer, or not covered by payment or credit',
        ], 'billing.payments.manage', 15);

        $dashboard->todo('admin', 'billing.refunds_waiting', fn (?User $user): array => [
            'label' => 'Refunds waiting for the provider',
            'count' => $user?->hasPermission('billing.transactions.view') ? Transaction::refundsAwaitingProvider()->count() : 0,
            'href' => route('admin.billing.transactions.index', ['waiting' => 1]),
            'tone' => 'warning',
            'icon' => 'Clock',
            'hint' => 'The amount stays held back until the provider confirms or staff settle it',
        ], 'billing.refunds.manage', 17);

        $dashboard->todo('admin', 'billing.notes_refused', fn (?User $user): array => [
            'label' => 'Invoices a credit or debit note could not be issued for',
            'count' => app(NoteRefusals::class)->open(),
            // Just the refused invoices, until each is cancelled, paid or fixed; the activity log names each and why.
            'href' => route('admin.billing.invoices.index', ['refused' => 1]),
            'tone' => 'warning',
            'icon' => 'AlertTriangle',
            'hint' => 'A cancellation or late fee was refused: open the invoice and see its activity',
        ], 'billing.invoices.manage', 18);

        $dashboard->todo('admin', 'billing.credit_owed', fn (?User $user): array => [
            'label' => 'Clients owing account credit',
            'count' => CreditLedgerEntry::owing()->distinct()->count('client_id'),
            'href' => route('admin.billing.credit.index', ['owing' => 1]),
            'tone' => 'warning',
            'icon' => 'Wallet',
            'hint' => 'Spent credit was taken back',
        ], 'billing.credit.manage', 16);

        $dashboard->health('admin', 'billing.gateways', fn (?User $user): array => $this->gatewayHealth(), 'billing.payments.manage', 40);
    }

    /**
     * @return array<string, mixed>
     */
    private function incomeCard(): array
    {
        $data = $this->dataset();
        $today = $this->companyDate();
        $monthStart = $today->startOfMonth();
        $previousStart = $monthStart->subMonth();
        $previousEnd = $previousStart->day(min($today->day, $previousStart->daysInMonth));
        $hint = sprintf('%s 1–%d vs. %s 1–%d', $monthStart->format('M'), $today->day, $previousStart->format('M'), $previousEnd->day);

        $view = function (array $daily) use ($today, $monthStart, $previousStart, $previousEnd, $hint): array {
            $current = $this->sumDays($daily, $monthStart, $today);
            $previous = $this->sumDays($daily, $previousStart, $previousEnd);

            return [
                'trend' => Trend::percent($current, $previous),
                'hint' => $hint,
                'sparkline' => $this->weeklyIncome($daily, $today),
                'amount' => $current,
            ];
        };

        return $this->moneyCard(
            'Income this month',
            $data['income'],
            $this->consolidateDaily($data['income']),
            $view,
            ['href' => route('admin.billing.transactions.index'), 'icon' => 'TrendingUp', 'primary' => true, 'asOf' => $data['computed_at'] ?? null],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function outstandingCard(): array
    {
        $data = $this->dataset();

        $view = fn (array $book): array => [
            'trend' => Trend::percent($book['outstanding'], $book['outstanding_month_ago'], upIsGood: false),
            'hint' => trans_choice('{0} No unpaid invoices|{1} 1 unpaid invoice|[2,*] :count unpaid invoices', $book['outstanding_count']),
            'sparkline' => $book['outstanding_weeks'],
            'amount' => $book['outstanding'],
        ];

        return $this->moneyCard(
            'Outstanding',
            $data['receivables'],
            $this->consolidateBooks($data['receivables']),
            $view,
            ['href' => route('admin.billing.invoices.index', ['status' => 'unpaid']), 'icon' => 'Receipt', 'primary' => true, 'asOf' => $data['computed_at'] ?? null],
            self::EMPTY_BOOK,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function overdueCard(): array
    {
        $data = $this->dataset();

        $view = function (array $book): array {
            $oldest = $book['oldest_due'] === null ? null : (int) CarbonImmutable::parse($book['oldest_due'])->diffInDays($this->companyDate());

            return [
                'trend' => Trend::percent($book['overdue'], $book['overdue_month_ago'], upIsGood: false),
                'hint' => $book['overdue_count'] === 0
                    ? 'Nothing overdue'
                    : trans_choice('{1} 1 invoice|[2,*] :count invoices', $book['overdue_count']).($oldest === null ? '' : ", oldest {$oldest} days"),
                'sparkline' => $book['overdue_weeks'],
                'amount' => $book['overdue'],
            ];
        };

        return [
            ...$this->moneyCard(
                'Overdue',
                $data['receivables'],
                $this->consolidateBooks($data['receivables']),
                $view,
                ['href' => route('admin.billing.invoices.index', ['status' => 'overdue']), 'icon' => 'AlertTriangle', 'tone' => 'danger', 'primary' => true, 'asOf' => $data['computed_at'] ?? null],
                self::EMPTY_BOOK,
            ),
            'count' => array_sum(array_column($data['receivables'], 'overdue_count')),
        ];
    }

    /**
     * Net income per month for the last 12 months (this month to date) against the same period a year earlier,
     * and per day for the last 30 days against the 30 before.
     *
     * @return array<string, mixed>
     */
    private function incomeChart(): array
    {
        $data = $this->dataset();
        $rollup = $this->rollup();
        $today = $this->companyDate();

        $view = function (array $daily, string $currency) use ($today): array {
            $months = $this->monthlyComparison($daily, $today, $currency);
            $days = $this->dailyComparison($daily, $today, $currency);

            return [
                'currency' => $currency,
                'series' => $months['series'],
                'summary' => $months['summary'],
                'ranges' => [
                    '12m' => ['label' => '12 months', 'xFormat' => 'month', ...$months],
                    '30d' => ['label' => '30 days', 'xFormat' => 'day', ...$days],
                ],
            ];
        };

        $codes = $rollup->codes(array_keys($data['income']));
        $consolidated = $this->consolidateDaily($data['income']);

        return [
            'title' => 'Income',
            'kind' => 'line',
            'xFormat' => 'month',
            'href' => route('admin.billing.transactions.index'),
            'empty' => 'No income recorded yet. Payments you record appear here.',
            'asOf' => $data['computed_at'] ?? null,
            ...$view($consolidated, $rollup->defaultCode()),
            'variants' => array_combine($codes, array_map(fn (string $code): array => $view($data['income'][$code] ?? [], $code), $codes)) ?: (object) [],
        ];
    }

    /**
     * This month's income per currency in its own currency, the share of each after conversion, and tax collected.
     *
     * @return array<string, mixed>
     */
    private function currencySplit(): array
    {
        $data = $this->dataset();
        $rollup = $this->rollup();
        $today = $this->companyDate();
        $amounts = [];

        foreach ($data['income'] as $code => $daily) {
            $amount = $this->sumDays($daily, $today->startOfMonth(), $today);

            if ($amount > 0) {
                $amounts[$code] = $amount;
            }
        }

        $converted = array_map(fn (int $amount, string $code): int => max(0, $rollup->toDefault($amount, $code) ?? 0), $amounts, array_keys($amounts));
        $total = array_sum($converted);
        $converted = array_combine(array_keys($amounts), $converted);

        return [
            'title' => 'Income by currency',
            'description' => 'This month, in each currency as received; shares at today\'s rates',
            'href' => route('admin.billing.transactions.index'),
            'empty' => 'No income this month yet.',
            'asOf' => $data['computed_at'] ?? null,
            'rows' => array_map(fn (string $code): array => [
                'key' => $code,
                'code' => $code,
                'label' => $rollup->name($code),
                'money' => ['amount' => $amounts[$code], 'currency' => $code],
                'share' => $total > 0 ? round($converted[$code] / $total * 100, 1) : 0,
            ], $rollup->order(array_keys($amounts))),
            'notesTitle' => 'Tax collected this month',
            'notes' => $data['tax'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function recentPayments(): array
    {
        return [
            'title' => 'Recent payments',
            'items' => array_values(Transaction::query()
                ->with(['client:id,first_name,last_name,company_name', 'invoice:id,number,proforma_number'])
                ->whereIn('type', [TransactionType::Payment, TransactionType::ManualIncome])
                ->where('amount_in', '>', 0)
                ->orderByDesc('occurred_at')->orderByDesc('id')
                ->limit(5)
                ->get()
                ->map(fn (Transaction $transaction): array => [
                    'title' => $transaction->client->display_name,
                    'subtitle' => collect([$transaction->invoice?->displayNumber() ?? 'Account credit', $transaction->occurred_at->diffForHumans()])->implode(' · '),
                    'href' => $transaction->invoice_id !== null ? route('admin.billing.invoices.show', $transaction->invoice_id) : route('admin.billing.transactions.index'),
                    'meta' => app(MoneyFormatter::class)->format(Money::of($transaction->amount_in, $transaction->currency)),
                    'money' => ['amount' => $transaction->amount_in, 'currency' => $transaction->currency],
                    'date' => $transaction->occurred_at->toIso8601String(),
                ])->all()),
            'href' => route('admin.billing.transactions.index'),
            'empty' => 'No payments yet.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function overdueTodo(): array
    {
        $receivables = $this->dataset()['receivables'];
        $count = array_sum(array_column($receivables, 'overdue_count'));
        $atRisk = collect($this->rollup()->order(array_keys($receivables)))
            ->filter(fn (string $code): bool => $receivables[$code]['overdue'] > 0)
            ->map(fn (string $code): string => $this->format($receivables[$code]['overdue'], $code))
            ->take(2)
            ->implode(' + ');

        return [
            'label' => 'Overdue invoices',
            'count' => $count,
            'href' => route('admin.billing.invoices.index', ['status' => 'overdue']),
            'tone' => 'danger',
            'icon' => 'Receipt',
            'hint' => $atRisk === '' ? null : $atRisk.' at risk',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function gatewayHealth(): array
    {
        $since = now()->subDays(7);
        $attempts = PaymentAttempt::query()->where('created_at', '>=', $since)
            ->whereIn('status', [AttemptStatus::Succeeded, AttemptStatus::Failed])
            ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')
            ->map(fn (mixed $total): int => (int) $total);
        $succeeded = $attempts->get(AttemptStatus::Succeeded->value, 0);
        $failed = $attempts->get(AttemptStatus::Failed->value, 0);
        $review = $this->ambiguousAttempts();
        $attempted = $succeeded + $failed;

        return [
            'label' => 'Payment gateways',
            'detail' => $attempted === 0
                ? 'No online payments in the last 7 days'
                : sprintf('%d of %d online payments succeeded (7 days)', $succeeded, $attempted),
            'value' => $review > 0 ? $review.' to review' : ($attempted === 0 ? 'Idle' : round($succeeded / $attempted * 100, 1).'%'),
            'tone' => $review > 0 ? 'danger' : ($attempted === 0 ? 'neutral' : ($failed / $attempted > 0.1 ? 'warning' : 'success')),
            'icon' => 'CreditCard',
            'href' => route('admin.billing.payment-attempts.index'),
        ];
    }

    /**
     * Unresolved attempts past the alert delay, plus booked payments refunded at the provider for more than the
     * credit left from them (#130).
     */
    private function ambiguousAttempts(): int
    {
        return PaymentAttempt::query()->where('status', AttemptStatus::Ambiguous)
            ->where('updated_at', '<=', now()->subMinutes($this->settings->int('gateways.ambiguous_alert_minutes', 30)))->count()
            + PaymentAttempt::toCheck([PaymentAttempt::CHECK_REFUND])->count();
    }

    /**
     * A money stat card: the consolidated figure as the base payload and one variant per currency.
     *
     * @param  array<string, mixed>  $byCurrency  per-currency input for $view
     * @param  mixed  $consolidated  the same input for all currencies in the default currency
     * @param  \Closure(mixed): array{trend: array<string, mixed>|null, hint: string, sparkline: list<int>, amount: int}  $view
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function moneyCard(string $label, array $byCurrency, mixed $consolidated, \Closure $view, array $extra, mixed $empty = []): array
    {
        $rollup = $this->rollup();
        $codes = $rollup->codes(array_keys($byCurrency));
        $card = function (array $view, string $currency): array {
            $amount = $view['amount'];
            unset($view['amount']);

            return [
                'value' => $this->format($amount, $currency),
                'money' => ['amount' => $amount, 'currency' => $currency],
                ...array_filter($view, fn (mixed $value): bool => $value !== null),
            ];
        };
        $variants = [];

        foreach ($codes as $code) {
            $variants[$code] = $card($view($byCurrency[$code] ?? $empty), $code);
        }

        return [
            'label' => $label,
            ...$card($view($consolidated), $rollup->defaultCode()),
            ...$extra,
            'variants' => $variants === [] ? (object) [] : $variants,
        ];
    }

    /**
     * @param  array<string, array<string, int>>  $daily
     * @return array<string, int>
     */
    private function consolidateDaily(array $daily): array
    {
        $rollup = $this->rollup();
        $total = [];

        foreach ($daily as $code => $days) {
            $convert = $rollup->converter((string) $code);

            foreach ($days as $day => $amount) {
                $total[$day] = ($total[$day] ?? 0) + ($convert === null ? 0 : $convert($amount));
            }
        }

        return $total;
    }

    /**
     * @param  array<string, array<string, mixed>>  $books
     * @return array<string, mixed>
     */
    private function consolidateBooks(array $books): array
    {
        $rollup = $this->rollup();
        $book = self::EMPTY_BOOK;

        foreach ($books as $code => $single) {
            foreach (['outstanding', 'overdue', 'outstanding_month_ago', 'overdue_month_ago'] as $key) {
                $book[$key] += $rollup->toDefault($single[$key], $code) ?? 0;
            }

            foreach (['outstanding_weeks', 'overdue_weeks'] as $key) {
                foreach ($single[$key] as $index => $amount) {
                    $book[$key][$index] += $rollup->toDefault($amount, $code) ?? 0;
                }
            }

            $book['outstanding_count'] += $single['outstanding_count'];
            $book['overdue_count'] += $single['overdue_count'];
            $book['oldest_due'] = $single['oldest_due'] !== null && ($book['oldest_due'] === null || $single['oldest_due'] < $book['oldest_due'])
                ? $single['oldest_due'] : $book['oldest_due'];
        }

        return $book;
    }

    /**
     * @param  array<string, int>  $daily
     * @return array{series: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    private function monthlyComparison(array $daily, CarbonImmutable $today, string $currency): array
    {
        $current = [];
        $previous = [];

        for ($offset = 11; $offset >= 0; $offset--) {
            $month = $today->startOfMonth()->subMonths($offset);
            $end = $offset === 0 ? $today : $month->endOfMonth();
            $lastYear = $month->subYear();
            $lastYearEnd = $offset === 0 ? $lastYear->day(min($today->day, $lastYear->daysInMonth)) : $lastYear->endOfMonth();
            $current[] = ['x' => $month->toDateString(), 'y' => $this->sumDays($daily, $month, $end)];
            $previous[] = ['x' => $month->toDateString(), 'y' => $this->sumDays($daily, $lastYear, $lastYearEnd)];
        }

        return [
            'series' => [
                ['name' => 'Last 12 months', 'points' => $current],
                ['name' => 'Year before', 'compare' => true, 'points' => $previous],
            ],
            'summary' => $this->summary($current, $previous, $currency, 'last 12 months', 'vs. year before'),
        ];
    }

    /**
     * @param  array<string, int>  $daily
     * @return array{series: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    private function dailyComparison(array $daily, CarbonImmutable $today, string $currency): array
    {
        $current = [];
        $previous = [];

        for ($offset = 29; $offset >= 0; $offset--) {
            $day = $today->subDays($offset);
            $current[] = ['x' => $day->toDateString(), 'y' => $daily[$day->toDateString()] ?? 0];
            $previous[] = ['x' => $day->toDateString(), 'y' => $daily[$day->subDays(30)->toDateString()] ?? 0];
        }

        return [
            'series' => [
                ['name' => 'Last 30 days', 'points' => $current],
                ['name' => '30 days before', 'compare' => true, 'points' => $previous],
            ],
            'summary' => $this->summary($current, $previous, $currency, 'last 30 days', 'vs. 30 days before'),
        ];
    }

    /**
     * @param  list<array{x: string, y: int}>  $current
     * @param  list<array{x: string, y: int}>  $previous
     * @return array<string, mixed>
     */
    private function summary(array $current, array $previous, string $currency, string $label, string $versus): array
    {
        $total = array_sum(array_column($current, 'y'));
        $before = array_sum(array_column($previous, 'y'));
        $trend = Trend::percent($total, $before);

        return [
            'money' => ['amount' => $total, 'currency' => $currency],
            'label' => $label,
            'trend' => $trend === null ? null : [...$trend, 'label' => $versus],
        ];
    }

    /**
     * Net income per week for the last 12 weeks ending today.
     *
     * @param  array<string, int>  $daily
     * @return list<int>
     */
    private function weeklyIncome(array $daily, CarbonImmutable $today): array
    {
        $weeks = [];

        for ($week = self::WEEKS - 1; $week >= 0; $week--) {
            $end = $today->subWeeks($week);
            $weeks[] = $this->sumDays($daily, $end->subDays(6), $end);
        }

        return $weeks;
    }

    /**
     * @param  array<string, int>  $daily
     */
    private function sumDays(array $daily, CarbonImmutable $from, CarbonImmutable $to): int
    {
        $start = $from->toDateString();
        $end = $to->toDateString();
        $sum = 0;

        foreach ($daily as $day => $amount) {
            if ($day >= $start && $day <= $end) {
                $sum += $amount;
            }
        }

        return $sum;
    }

    /**
     * Per-currency figures shared by every Billing widget, cached briefly:
     * - income: net income per day for 24 months (one grouped query on transactions(type, occurred_at));
     * - receivables: open and recently closed invoices for the outstanding/overdue KPIs and their history;
     * - tax: tax collected on invoices paid this month, per tax name and currency;
     * - computed_at: when it was computed, ISO 8601 in the company's time zone.
     *
     * @return array{income: array<string, array<string, int>>, receivables: array<string, array<string, mixed>>, tax: list<array{label: string, money: array{amount: int, currency: string}}>, computed_at?: string}
     */
    private function dataset(): array
    {
        $today = $this->companyDate();

        /** @var array{income: array<string, array<string, int>>, receivables: array<string, array<string, mixed>>, tax: list<array{label: string, money: array{amount: int, currency: string}}>, computed_at?: string} */
        // The dataset is the same for every user (permissions apply per widget); the key follows the local day.
        return Cache::flexible('dashboard.billing.'.$this->calendar->timezone().'.'.$today->toDateString(), [self::CACHE_SECONDS, self::STALE_SECONDS], fn (): array => [
            'income' => $this->incomeByDay($this->calendar->dayBounds($today->startOfMonth()->subMonths(23))[0]),
            'receivables' => $this->receivables($today),
            'tax' => $this->taxCollected($this->calendar->dayBounds($today->startOfMonth())[0]),
            'computed_at' => $this->calendar->now()->toIso8601String(),
        ], ['seconds' => 30]);
    }

    /**
     * @return array<string, array<string, int>>
     */
    private function incomeByDay(CarbonImmutable $since): array
    {
        $income = [];

        // Summed per currency and company-local day in SQL: one row per day, never the raw transactions.
        // GROUP BY position works on all three drivers.
        Transaction::query()
            ->whereIn('type', self::INCOME_TYPES)
            ->where('occurred_at', '>=', $since)
            ->selectRaw('currency, '.$this->calendar->sqlLocalDate('occurred_at').' as day, sum(amount_in) as amount_in, sum(amount_out) as amount_out', [$this->calendar->sqlZoneArgument()])
            ->groupByRaw('1, 2')
            ->toBase()
            ->get()
            ->each(function (object $row) use (&$income): void {
                $day = substr((string) $row->day, 0, 10);
                $income[(string) $row->currency][$day] = ($income[(string) $row->currency][$day] ?? 0) + (int) $row->amount_in - (int) $row->amount_out;
            });

        return $income;
    }

    /**
     * Per-currency receivable books: today's totals plus weekly snapshots (12 points ending today) and the value 30
     * days ago, for outstanding and overdue. Earlier points are reconstructed from issue, paid and cancelled days in
     * the company's calendar. Overdue follows today's rule: an invoice still open today counts only while it is
     * Unpaid (not Payment pending or Collections); one closed since then counts while it was open and past due.
     *
     * Summed in SQL, one row per currency: the open invoices and those closed within the window (three disjoint
     * status sets, each on an index) feed one grouped query with a conditional sum per snapshot day.
     *
     * @return array<string, array<string, mixed>>
     */
    private function receivables(CarbonImmutable $today): array
    {
        $since = $this->calendar->dayBounds($today->subWeeks(self::WEEKS)->subDay())[0];
        $zone = $this->calendar->sqlZoneArgument();
        $base = fn () => Invoice::query()->toBase()->whereNotNull('issue_date');
        // Per invoice: whether it is open today, whether it can count as overdue, the amount it carried while open,
        // and its local issue, due and closing days.
        $closed = 'currency, 0 as is_open, 1 as counts_overdue, total as amount, date(issue_date) as issue_day, date(due_date) as due_day, '
            .$this->calendar->sqlLocalDate('paid_at').' as paid_day, '.$this->calendar->sqlLocalDate('cancelled_at').' as cancelled_day';
        $invoices = $base()->whereIn('status', InvoiceStatus::payable())
            ->selectRaw('currency, 1 as is_open, case when status = ? then 1 else 0 end as counts_overdue, balance as amount, date(issue_date) as issue_day, date(due_date) as due_day, null as paid_day, null as cancelled_day', [InvoiceStatus::Unpaid->value])
            ->unionAll($base()->whereIn('status', [InvoiceStatus::Paid, InvoiceStatus::Refunded])->where('paid_at', '>=', $since)->selectRaw($closed, [$zone, $zone]))
            ->unionAll($base()->where('status', InvoiceStatus::Cancelled)->where('cancelled_at', '>=', $since)->selectRaw($closed, [$zone, $zone]));

        $todayDay = $today->toDateString();
        $overdueToday = 'is_open = 1 and counts_overdue = 1 and due_day < ?';
        $openOn = 'issue_day <= ? and (paid_day is null or paid_day > ?) and (cancelled_day is null or cancelled_day > ?)';
        $select = ['currency', 'sum(case when is_open = 1 then amount else 0 end) as outstanding', 'sum(is_open) as outstanding_count',
            "sum(case when {$overdueToday} then amount else 0 end) as overdue", "sum(case when {$overdueToday} then 1 else 0 end) as overdue_count",
            "min(case when {$overdueToday} then due_day end) as oldest_due"];
        $bindings = [$todayDay, $todayDay, $todayDay];

        // Points 0–10: weekly snapshots, oldest first; 11: 30 days ago.
        foreach (range(0, self::WEEKS - 1) as $point) {
            $day = ($point < self::WEEKS - 1 ? $today->subWeeks(self::WEEKS - 1 - $point) : $today->subDays(30))->toDateString();
            $select[] = "sum(case when {$openOn} then amount else 0 end) as open_{$point}";
            $select[] = "sum(case when {$openOn} and counts_overdue = 1 and due_day < ? then amount else 0 end) as late_{$point}";
            array_push($bindings, $day, $day, $day, $day, $day, $day, $day);
        }

        $books = [];

        foreach (DB::query()->fromSub($invoices, 'receivables')->selectRaw(implode(', ', $select), $bindings)->groupBy('currency')->orderBy('currency')->get() as $row) {
            $sums = (array) $row;
            $weeks = fn (string $prefix): array => array_map(fn (int $point): int => (int) $sums[$prefix.$point], range(0, self::WEEKS - 2));

            $books[(string) $row->currency] = [
                'outstanding' => (int) $row->outstanding,
                'outstanding_count' => (int) $row->outstanding_count,
                'overdue' => (int) $row->overdue,
                'overdue_count' => (int) $row->overdue_count,
                'oldest_due' => $row->oldest_due === null ? null : substr((string) $row->oldest_due, 0, 10),
                'outstanding_weeks' => [...$weeks('open_'), (int) $row->outstanding],
                'outstanding_month_ago' => (int) $sums['open_'.(self::WEEKS - 1)],
                'overdue_weeks' => [...$weeks('late_'), (int) $row->overdue],
                'overdue_month_ago' => (int) $sums['late_'.(self::WEEKS - 1)],
            ];
        }

        return $books;
    }

    /**
     * @return list<array{label: string, money: array{amount: int, currency: string}}>
     */
    private function taxCollected(CarbonImmutable $since): array
    {
        $totals = [];
        // Summed per currency and tax name in SQL, one branch per tax slot.
        $paid = fn () => Invoice::query()->toBase()->where('status', InvoiceStatus::Paid)->where('paid_at', '>=', $since);
        $first = $paid()->where('tax1_amount', '!=', 0)->selectRaw('currency, tax1_name as name, sum(tax1_amount) as amount')->groupBy('currency', 'tax1_name');
        $second = $paid()->where('tax2_amount', '!=', 0)->selectRaw('currency, tax2_name as name, sum(tax2_amount) as amount')->groupBy('currency', 'tax2_name');

        $first->unionAll($second)->get()->each(function (object $row) use (&$totals): void {
            $key = $row->currency.'|'.($row->name ?: 'Tax');
            $totals[$key] = ($totals[$key] ?? 0) + (int) $row->amount;
        });

        ksort($totals);

        return array_map(function (string $key, int $amount): array {
            [$currency, $label] = explode('|', $key, 2);

            return ['label' => $label, 'money' => ['amount' => $amount, 'currency' => $currency]];
        }, array_keys($totals), $totals);
    }

    private function format(int $amount, string $code): string
    {
        return $this->rollup()->format($amount, $code);
    }

    /**
     * Request-scoped (bound in CoreServiceProvider), so Billing and Services share one read of the currency table.
     */
    private function rollup(): CurrencyRollup
    {
        return app(CurrencyRollup::class);
    }
}
