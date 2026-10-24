<?php

use App\Contracts\Billing\GatewayDirectory;
use App\Contracts\Billing\GatewayDriver;
use App\Contracts\Billing\GatewayRegistry;
use App\Contracts\Billing\HostedPaymentDriver;
use App\Contracts\Billing\InvoiceQueries;
use App\Contracts\Notifications\MergeFieldRegistry;
use App\Contracts\Orders\PaymentGuard;
use App\Modules\Billing\Actions\CaptureInvoice;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\Gateway;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PayMethod;
use App\Modules\Billing\Models\Quote;
use App\Modules\Core\Models\Client;
use App\Support\Automation\Automation;
use App\Support\Dashboard\Dashboard;
use App\Support\Navigation\Navigation;
use App\Support\Permissions\PermissionRegistry;
use App\Support\Search\GlobalSearch;
use App\Support\Settings\SettingDefinition;
use App\Support\Settings\SettingsRegistry;
use Illuminate\Support\Arr;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/helpers.php';

test('every billing permission used by the module is registered', function () {
    $registry = app(PermissionRegistry::class);
    $used = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Modules/Billing')));

    foreach ($files as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            preg_match_all("/'(billing\.[a-z_]+\.(?:view|manage))'/", (string) file_get_contents($file->getPathname()), $matches);
            $used = [...$used, ...$matches[1]];
        }
    }

    expect($used)->not->toBeEmpty();
    foreach (array_unique($used) as $permission) {
        expect($registry->exists($permission))->toBeTrue("{$permission} is not registered");
    }
});

test('every billing config default has a setting definition', function () {
    // Billing's own section, and the gateway log retention in its security-tier section.
    $sections = app(SettingsRegistry::class)->sections();
    $keys = collect([...$sections['billing']['settings'], ...$sections['billing_retention']['settings']])->map(fn (SettingDefinition $definition) => $definition->key);
    $defaults = collect(Arr::dot(config('billing')))->keys()
        ->map(fn (string $key) => 'billing.'.preg_replace('/\.\d+$/', '', $key))->unique();

    expect($defaults->diff($keys)->values()->all())->toBe([]);
});

test('every registered gateway declares REDIRECT exactly when it is a hosted payment driver', function () {
    $drivers = app(GatewayRegistry::class)->drivers();

    // Billing's own drivers and the extension modules' (PaymentsStripe, PaymentsNepal), hosted and offline.
    expect(array_keys($drivers))->toContain('bank_transfer', 'manual', 'sandbox_card', 'stripe', 'khalti', 'esewa');
    foreach ($drivers as $key => $driver) {
        expect($driver->supports(GatewayDriver::REDIRECT))->toBe($driver instanceof HostedPaymentDriver, "{$key} declares REDIRECT without being a hosted payment driver, or the other way round");
    }
});

test('billing automation tasks are registered in pipeline order', function () {
    $tasks = array_keys(array_filter(app(Automation::class)->ordered(), fn ($task, $key) => str_starts_with($key, 'billing.'), ARRAY_FILTER_USE_BOTH));

    expect($tasks)->toBe(['billing.late_fees', 'billing.auto_capture', 'billing.reminders', 'billing.auto_cancel', 'billing.payment_reconciliation', 'billing.gateway_refund_check', 'billing.housekeeping', 'billing.gateway_log_prune']);
});

test('navigation shows billing items according to permissions', function () {
    $staff = actingAsStaff(['billing.invoices.view']);
    $groups = collect(app(Navigation::class)->forUser('admin', $staff));
    $billing = collect($groups->firstWhere('title', 'Billing')['items'] ?? [])->pluck('title');

    expect($billing->all())->toBe(['Invoices'])
        ->and(collect(app(Navigation::class)->clientLinks(5, $staff))->pluck('href')->first())->toEndWith('/admin/billing/invoices?client=5');

    [$user] = actingAsClient();
    $client = collect(collect(app(Navigation::class)->forUser('client', $user))->firstWhere('title', 'Billing')['items'])->pluck('title');
    expect($client->all())->toBe(['Invoices', 'Quotes', 'Credit & funds', 'Payment methods']);
});

test('dashboards show billing cards', function () {
    $staff = billingStaff();
    billingClient();
    Invoice::factory()->overdue()->create();

    $this->get(route('admin.dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('widgets', fn ($widgets) => collect($widgets)->firstWhere('key', 'billing.overdue')['count'] === 1
            && collect($widgets)->firstWhere('key', 'billing.unpaid')['money']['currency'] === 'USD'));

    $client = Client::factory()->withOwner()->create();
    $this->actingAs($client->users()->firstOrFail());
    issueInvoice($client, [4_200]);

    $this->get(route('client.dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('cards', fn ($cards) => collect($cards)->firstWhere('key', 'billing.unpaid')['money']['amount'] === 4_200
            && collect($cards)->contains('key', 'billing.credit')));
});

test('global search finds invoices by number for permitted staff', function () {
    $staff = actingAsStaff(['billing.invoices.view']);
    $invoice = Invoice::factory()->create(['number' => 'INV-77731']);

    $results = collect(app(GlobalSearch::class)->search('77731', $staff))->firstWhere('group', 'Invoices');

    expect($results['results'][0]['title'])->toBe('INV-77731')
        ->and($results['results'][0]['href'])->toBe(route('admin.billing.invoices.show', $invoice))
        ->and(app(GlobalSearch::class)->search('77731', actingAsStaff(['support.*'])))->toBe([]);
});

test('merge fields resolve invoices, quotes and transactions with the client id', function () {
    $invoice = issueInvoice(billingClient(), [1_234]);
    $transaction = payInvoice($invoice, 1_234);
    $quote = Quote::factory()->create();
    $registry = app(MergeFieldRegistry::class);

    expect($registry->resolve('invoice', $invoice->id))->toMatchArray(['client_id' => $invoice->client_id, 'number' => $invoice->number, 'total' => '$12.34'])
        ->and($registry->resolve('quote', $quote->id))->toHaveKey('client_id', $quote->client_id)
        ->and($registry->resolve('transaction', $transaction->id))->toMatchArray(['client_id' => $invoice->client_id, 'invoice_id' => $invoice->id]);
});

test('the admin dashboard gets billing widgets and to-dos, the nav gets badges and quick-create', function () {
    $staff = billingStaff();
    $client = billingClient();
    Invoice::factory()->overdue()->create(['client_id' => $client->id]);
    payInvoice(issueInvoice($client, [2_500]), 2_500);

    $dashboard = app(Dashboard::class);
    $widgets = collect($dashboard->resolveWidgets('admin', $staff));
    $chart = $widgets->firstWhere('key', 'billing.income_chart');

    expect($chart)->type->toBe('chart')
        ->and(collect($dashboard->resolveWidgets('admin', $staff, deferred: true))->pluck('key'))->toContain('billing.income_chart')
        ->and($chart['series'][0]['points'])->toHaveCount(12)
        ->and(collect($chart['series'][0]['points'])->last()['y'])->toBe(2_500)
        ->and($chart['ranges']['30d']['series'][0]['points'])->toHaveCount(30)
        ->and(collect($chart['ranges']['30d']['series'][0]['points'])->last()['y'])->toBe(2_500)
        ->and($widgets->firstWhere('key', 'billing.recent_payments')['items'])->toHaveCount(1)
        ->and(collect($dashboard->resolveTodos('admin', $staff))->firstWhere('key', 'billing.overdue')['count'])->toBe(1);

    $billingNav = collect(collect(app(Navigation::class)->forUser('admin', $staff))->firstWhere('title', 'Billing')['items']);
    expect($billingNav->firstWhere('title', 'Invoices')['badge'])->toBe(1)
        ->and(collect(app(Navigation::class)->quickActions($staff))->pluck('title')->all())->toContain('New invoice', 'New quote');
});

test('billing implements the gateway directory and invoice queries contracts', function () {
    sandboxGateway();
    Gateway::factory()->manual()->create();
    $invoice = issueInvoice(billingClient(), [1_000]);
    $untouched = issueInvoice(billingClient(['email' => 'second@example.com']), [1_000]);
    payInvoice($invoice, 500);

    expect(app(GatewayDirectory::class)->available('USD'))->toBe([['key' => 'sandbox_card', 'label' => 'Credit card']])
        ->and(app(GatewayDirectory::class)->available('EUR'))->toBe([])
        ->and(app(InvoiceQueries::class)->hasPayments($invoice->id))->toBeTrue()
        ->and(app(InvoiceQueries::class)->hasPayments($untouched->id))->toBeFalse();
});

test('payments are refused when the order payment guard says so', function () {
    sandboxGateway();
    app()->instance(PaymentGuard::class, new class implements PaymentGuard
    {
        public function refusalReason(int $invoiceId): ?string
        {
            return 'This order is under fraud review.';
        }
    });
    $client = billingClient();
    $card = PayMethod::factory()->create(['client_id' => $client->id]);
    $invoice = issueInvoice($client, [1_000], now()->toImmutable());

    expect(fn () => app(CaptureInvoice::class)->handle($invoice, 'sandbox_card', $card->token, $card))
        ->toThrow(BillingException::class, 'This order is under fraud review.');

    $this->artisan('automation:run', ['--only' => 'billing.auto_capture'])->assertSuccessful();
    expect($invoice->fresh()->capture_attempts)->toBe(0);
});

test('invoice queries report whether any billing document uses a currency', function () {
    $queries = app(InvoiceQueries::class);
    billingClient();

    expect($queries->hasDocumentsInCurrency('USD'))->toBeFalse();

    Quote::factory()->create();

    expect($queries->hasDocumentsInCurrency('usd'))->toBeTrue()
        ->and($queries->hasDocumentsInCurrency('NPR'))->toBeFalse();
});
