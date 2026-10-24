<?php

namespace App\Modules\PaymentsNepal\Drivers;

use App\Contracts\Billing\ConnectionTester;
use App\Contracts\Billing\HostedPaymentDriver;
use App\Contracts\Billing\ResumesHostedPayment;
use App\Contracts\Integrations\ProvidesLogo;
use App\Data\Billing\AmountLimits;
use App\Data\Billing\ChargeResult;
use App\Data\Billing\GatewayConfig;
use App\Data\Billing\LookupResult;
use App\Data\Billing\PaymentAttemptRef;
use App\Data\Billing\PaymentIntent;
use App\Data\Billing\RedirectInstruction;
use App\Data\Billing\RefundRequest;
use App\Data\Billing\ReturnHint;
use App\Data\Integrations\ConnectionCheck;
use App\Data\Integrations\Endpoint;
use App\Data\Integrations\IntegrationLogo;
use App\Modules\PaymentsNepal\Support\ProviderException;
use App\Modules\PaymentsNepal\Support\ProviderHttp;
use App\Support\Billing\AbstractGatewayDriver;
use App\Support\Integrations\Endpoints;
use App\Support\Integrations\OutboundAddressRefused;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Throwable;

/**
 * Khalti KPG-2 web checkout (docs/product/payments-nepal.md §2.1, docs.khalti.com/khalti-epayment).
 *
 * initiate() asks Khalti for a payment session (pidx) and sends the customer to its payment_url. Khalti's
 * browser return is unsigned, so it is a hint only: lookup() of the pidx stored on the attempt is the only
 * source of truth, and only "Completed" counts as paid. Refunds (full or partial) go through Khalti's API.
 *
 * "Continue payment" (#251): the lookup doesn't return the payment_url and Khalti doesn't document building it from
 * the pidx, so initiate() asks Billing to keep it (encrypted, on the attempt). resumeUrl() hands it back only while a
 * lookup says the session is still Initiated.
 */
final class KhaltiDriver extends AbstractGatewayDriver implements ConnectionTester, HostedPaymentDriver, ProvidesLogo, ResumesHostedPayment
{
    public const KEY = 'khalti';

    /** Shown with every refund Khalti declines. */
    public const BANK_FUNDED_NOTE = 'If the payment was made from a bank account, refund it in the Khalti merchant portal, then record the refund here';

    /** How long resumeUrl()'s lookup answer may stand in for the lookup Billing makes right after a refused resume. */
    private const RESUME_ANSWER_SECONDS = 15;

    /**
     * The answer behind a refused resumeUrl(), handed once to the next lookup() of the same pidx: Billing settles a
     * refused resume from it, so a click costs one provider call.
     *
     * @var array{pidx: string, at: float, result: LookupResult}|null
     */
    private ?array $resumeAnswer = null;

    public function __construct(private ProviderHttp $http) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return 'Khalti';
    }

    /** Khalti's official docs logo (docs/product/payments-nepal.md §8.6), served from public/images/gateways. */
    public function logo(): IntegrationLogo
    {
        return new IntegrationLogo(asset('images/gateways/khalti.svg'), __('Pay with Khalti'));
    }

    /**
     * Partial refunds are advertised only when payments_nepal.khalti.partial_refunds is on (see the config).
     */
    public function capabilities(): array
    {
        return [self::REDIRECT, self::LOOKUP, self::REFUND, ...(self::partialRefunds() ? [self::PARTIAL_REFUND] : [])];
    }

    public function currencies(): array
    {
        return ['NPR'];
    }

    public function settingsFields(): array
    {
        return [
            ['key' => 'sandbox', 'label' => 'Sandbox mode', 'type' => 'boolean', 'default' => true,
                'help' => 'Uses dev.khalti.com with the key from test-admin.khalti.com; no real money moves. Not available in production.'],
            ['key' => 'secret_key', 'label' => 'Secret key', 'type' => 'secret', 'required' => true,
                'help' => 'Merchant dashboard → Keys → "Live secret key" (admin.khalti.com; in sandbox mode, the one from test-admin.khalti.com).'],
        ];
    }

    public function limits(GatewayConfig $gateway): AmountLimits
    {
        return new AmountLimits(min: self::intConfig('khalti.min_amount', 1000));
    }

    public function attemptTtl(GatewayConfig $gateway): CarbonInterval
    {
        return CarbonInterval::minutes(self::intConfig('khalti.attempt_ttl_minutes', 60));
    }

    public function initiate(GatewayConfig $gateway, PaymentIntent $intent): RedirectInstruction
    {
        if ($intent->amount->currency !== 'NPR') {
            throw new ProviderException("Khalti only takes NPR, not {$intent->amount->currency}.");
        }

        $customer = array_filter([
            'name' => $intent->customerName !== null ? mb_substr($intent->customerName, 0, 100) : null,
            'email' => $intent->customerEmail,
            'phone' => $intent->customerPhone,
        ], fn (?string $value): bool => $value !== null && $value !== '');

        $api = ProviderHttp::endpoint($gateway, 'khalti.api');
        $response = $this->client($gateway, $api)->post($api->url('epayment/initiate/'), array_filter([
            'return_url' => $intent->returnUrl,
            'website_url' => (string) config('app.url'),
            'amount' => $intent->amount->amount,
            'purchase_order_id' => $intent->reference,
            'purchase_order_name' => mb_substr($intent->description, 0, 100) ?: $intent->reference,
            'customer_info' => $customer !== [] ? $customer : null,
        ], fn (mixed $value): bool => $value !== null));

        $pidx = $response->json('pidx');
        $paymentUrl = $response->json('payment_url');

        if (! $response->successful() || ! is_string($pidx) || ! self::isProviderRef($pidx) || ! is_string($paymentUrl) || ! $this->isKhaltiUrl($paymentUrl)) {
            throw new ProviderException('Khalti initiate failed (HTTP '.$response->status().') '.ProviderHttp::summary($response->json()));
        }

        return new RedirectInstruction($paymentUrl, $pidx, self::sessionExpiry($response->json('expires_at'), $response->json('expires_in')), keepForResume: true);
    }

    /**
     * The payment_url kept from initiate(), only while Khalti's lookup of the stored pidx says Initiated (its page is
     * served then; Pending means the payer already paid and the bank hasn't confirmed). Expired, cancelled, completed,
     * unreachable or any other answer gives null, as does a kept URL that fails the Khalti host check or names another
     * pidx; those two are refused before Khalti is asked.
     */
    public function resumeUrl(GatewayConfig $gateway, PaymentAttemptRef $attempt): ?string
    {
        if ($attempt->resumeUrl === null || $attempt->providerRef === null || ! $this->isKhaltiUrl($attempt->resumeUrl)
            || self::urlPidx($attempt->resumeUrl) !== $attempt->providerRef
            || ($attempt->expiresAt !== null && ! $attempt->expiresAt->isFuture())) {
            return null;
        }

        $this->resumeAnswer = null; // always a fresh answer here, never one left by an earlier click
        $result = $this->lookup($gateway, $attempt);
        $status = $result->raw['status'] ?? null;

        if ($result->status === LookupResult::PENDING && ! $result->retryable && is_string($status) && mb_strtolower(trim($status)) === 'initiated') {
            return $attempt->resumeUrl;
        }

        // Refused: Billing settles from this answer next. Never kept on a reopen, where the payer acts on the page next.
        $this->resumeAnswer = ['pidx' => $attempt->providerRef, 'at' => microtime(true), 'result' => $result];

        return null;
    }

    /**
     * Khalti's own link expiry (expires_at, else now + expires_in seconds). Ignored when missing or implausible
     * (not between 1 minute and 24 hours ahead); the attempt then keeps attemptTtl().
     */
    private static function sessionExpiry(mixed $expiresAt, mixed $expiresIn): ?CarbonImmutable
    {
        $at = null;

        if (is_string($expiresAt) && $expiresAt !== '') {
            try {
                $at = CarbonImmutable::parse($expiresAt);
            } catch (Throwable) {
                $at = null;
            }
        }

        if ($at === null && is_numeric($expiresIn)) {
            $at = CarbonImmutable::now()->addSeconds((int) $expiresIn);
        }

        return $at !== null && $at->greaterThan(now()->addMinute()) && $at->lessThan(now()->addDay()) ? $at : null;
    }

    public function parseReturn(GatewayConfig $gateway, Request $request): ReturnHint
    {
        $reference = $request->query('purchase_order_id', $request->query('ref'));
        $pidx = $request->query('pidx');
        $status = $request->query('status');

        return new ReturnHint(
            reference: is_string($reference) && preg_match('/^[a-z0-9-]{3,40}$/', $reference) === 1 ? $reference : null,
            providerRef: is_string($pidx) && self::isProviderRef($pidx) ? $pidx : null,
            claimedStatus: is_string($status) ? mb_substr($status, 0, 40) : null,
            signatureValid: false,
            raw: array_filter([
                'pidx' => is_string($pidx) ? mb_substr($pidx, 0, 64) : null,
                'status' => is_string($status) ? mb_substr($status, 0, 40) : null,
                'transaction_id' => is_string($request->query('transaction_id')) ? mb_substr((string) $request->query('transaction_id'), 0, 64) : null,
                'total_amount' => is_string($request->query('total_amount')) ? mb_substr((string) $request->query('total_amount'), 0, 20) : null,
                'mobile' => ProviderHttp::maskMobile($request->query('mobile')),
            ], fn (?string $value): bool => $value !== null),
        );
    }

    /**
     * Looks up the pidx stored on the attempt (never one from the browser). Expired and User canceled come back
     * as HTTP 400 with a normal body, so the body is read before the status code.
     */
    public function lookup(GatewayConfig $gateway, PaymentAttemptRef $attempt): LookupResult
    {
        if ($attempt->providerRef === null || ! self::isProviderRef($attempt->providerRef)) {
            return new LookupResult(LookupResult::NOT_FOUND, raw: ['error' => 'no pidx stored for this attempt']);
        }

        $answer = $this->resumeAnswer;
        $this->resumeAnswer = null;

        if ($answer !== null && $answer['pidx'] === $attempt->providerRef && microtime(true) - $answer['at'] < self::RESUME_ANSWER_SECONDS) {
            return $answer['result'];
        }

        try {
            $api = ProviderHttp::endpoint($gateway, 'khalti.api');
            $response = $this->http->lookup($attempt->interactive)
                ->withOptions($api->options)
                ->withHeaders(['Authorization' => 'Key '.self::secret($gateway)])
                ->asJson()
                ->post($api->url('epayment/lookup/'), ['pidx' => $attempt->providerRef]);
        } catch (ConnectionException) {
            return new LookupResult(LookupResult::PENDING, raw: ['error' => 'Khalti unreachable'], retryable: true);
        } catch (ProviderException $exception) {
            return new LookupResult(LookupResult::PENDING, raw: ['error' => $exception->getMessage()], retryable: true);
        }

        return $this->lookupResult($response, $attempt->providerRef);
    }

    /**
     * Full refund with an empty body, partial with the amount in paisa. Khalti returns no refund id, so the
     * refund transaction keeps no gateway reference. Bank-funded payments also need the payer's mobile number,
     * which we never store in full: those refunds fail here and are made in the Khalti portal, then recorded.
     */
    public function refund(GatewayConfig $gateway, RefundRequest $request): ChargeResult
    {
        if ($request->amount->currency !== 'NPR' || $request->amount->amount <= 0) {
            return new ChargeResult(ChargeResult::DECLINED, declineReason: 'Khalti refunds must be a positive NPR amount');
        }

        // Defence in depth: Billing never asks for a partial refund without the capability, but an amount in an
        // unproven unit must never reach Khalti.
        if ($request->isPartial() && ! self::partialRefunds()) {
            return new ChargeResult(ChargeResult::DECLINED, declineReason: 'partial refunds through Khalti are turned off');
        }

        $endpoint = ProviderHttp::endpoint($gateway, 'khalti.refund');

        try {
            $response = $this->client($gateway, $endpoint)->post($endpoint->url(rawurlencode($request->providerTxnId).'/refund/'), $request->isPartial() ? ['amount' => $request->amount->amount] : []);
        } catch (ConnectionException) {
            // The refund may still have gone through: it stays pending until confirmed.
            return new ChargeResult(ChargeResult::ERROR, declineReason: 'no answer from Khalti; the refund stays pending until it is confirmed', raw: ['transaction_id' => $request->providerTxnId]);
        }

        $raw = ['transaction_id' => $request->providerTxnId, 'amount' => $request->amount->amount, 'partial' => $request->isPartial(), 'http_status' => $response->status(), 'detail' => ProviderHttp::summary($response->json())];

        // A server error or request timeout says nothing about whether the refund was made: indeterminate.
        if ($response->serverError() || $response->status() === 408) {
            return new ChargeResult(ChargeResult::ERROR, declineReason: 'Khalti answered HTTP '.$response->status().'; the refund stays pending until it is confirmed', raw: $raw);
        }

        // Bank-funded payments are refunded in the Khalti portal for v1 (#27): the API needs the payer's full mobile number,
        // which is never stored. The reason staff see says so.
        if (! $response->successful()) {
            return new ChargeResult(ChargeResult::DECLINED, declineReason: mb_substr(ProviderHttp::summary($response->json()) ?: 'HTTP '.$response->status(), 0, 150).'. '.self::BANK_FUNDED_NOTE, raw: $raw);
        }

        return new ChargeResult(ChargeResult::SUCCESS, raw: $raw);
    }

    /**
     * A lookup of a made-up pidx: Khalti answers 401 for a rejected key and "Not found." for an accepted one.
     */
    public function testConnection(GatewayConfig $gateway): ConnectionCheck
    {
        try {
            $mode = ProviderHttp::mode($gateway);
        } catch (ProviderException $exception) {
            return new ConnectionCheck(false, $exception->getMessage());
        }

        if (self::secret($gateway) === '') {
            return new ConnectionCheck(false, __('Enter and save the secret key first.'));
        }

        try {
            $api = ProviderHttp::endpoint($gateway, 'khalti.api');
            $response = $this->client($gateway, $api)->post($api->url('epayment/lookup/'), ['pidx' => 'MoharConnectionTest']);
        } catch (ConnectionException) {
            return new ConnectionCheck(false, __('Couldn\'t reach Khalti (:mode). Check the server\'s outbound connection.', ['mode' => $mode]));
        }

        return match (true) {
            $response->status() === 401 || $response->status() === 403 => new ConnectionCheck(false, __('Khalti rejected the secret key (:mode mode). Use the key for this mode.', ['mode' => $mode])),
            in_array($response->status(), [200, 400, 404], true) => new ConnectionCheck(true, __('Khalti accepted the secret key (:mode mode).', ['mode' => $mode])),
            default => new ConnectionCheck(false, __('Khalti answered with HTTP :status. Try again later.', ['status' => $response->status()])),
        };
    }

    private function lookupResult(Response $response, string $pidx): LookupResult
    {
        $body = $response->json();
        $body = is_array($body) ? $body : [];
        $status = is_string($body['status'] ?? null) ? $body['status'] : null;
        $raw = array_filter([
            'http_status' => $response->status(),
            'pidx' => is_string($body['pidx'] ?? null) ? $body['pidx'] : null,
            'status' => $status,
            'total_amount' => is_int($body['total_amount'] ?? null) ? $body['total_amount'] : null,
            'transaction_id' => is_string($body['transaction_id'] ?? null) ? $body['transaction_id'] : null,
            'fee' => is_int($body['fee'] ?? null) ? $body['fee'] : null,
            'refunded' => is_bool($body['refunded'] ?? null) ? $body['refunded'] : null,
            'error' => $status === null ? ProviderHttp::summary($body) : null,
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        if ($status === null) {
            return match (true) {
                $response->status() === 404 => new LookupResult(LookupResult::NOT_FOUND, raw: $raw),
                // The key was rejected (e.g. rotated after the payment started): staff must act, so the attempt goes to
                // review. It stays open and is polled again, so it settles once the key is fixed.
                in_array($response->status(), [401, 403], true) => new LookupResult(LookupResult::AMBIGUOUS, raw: [...$raw, 'error' => 'Khalti rejected the secret key (HTTP '.$response->status().'); check the gateway settings']),
                // Server trouble or rate limiting: transient, try again later.
                $response->serverError(), $response->status() === 429 => new LookupResult(LookupResult::PENDING, raw: $raw, retryable: true),
                default => new LookupResult(LookupResult::AMBIGUOUS, raw: $raw),
            };
        }

        if (isset($raw['pidx']) && $raw['pidx'] !== $pidx) {
            return new LookupResult(LookupResult::AMBIGUOUS, raw: [...$raw, 'error' => 'lookup answered for another pidx']);
        }

        $mapped = match (mb_strtolower(trim($status))) {
            'completed' => LookupResult::SUCCEEDED,
            'pending', 'initiated' => LookupResult::PENDING,
            'expired' => LookupResult::EXPIRED,
            'user canceled', 'user cancelled', 'canceled', 'cancelled', 'failed' => LookupResult::CANCELLED,
            'refunded' => LookupResult::REFUNDED,
            'partially refunded' => LookupResult::PARTIALLY_REFUNDED,
            // Khalti: "for any other status, hold the transaction and contact Khalti".
            default => LookupResult::AMBIGUOUS,
        };

        return new LookupResult(
            status: $mapped,
            providerTxnId: $raw['transaction_id'] ?? null,
            amount: $raw['total_amount'] ?? null,
            currency: 'NPR',
            fee: $mapped === LookupResult::SUCCEEDED ? max(0, $raw['fee'] ?? 0) : 0,
            raw: $raw,
        );
    }

    private function client(GatewayConfig $gateway, Endpoint $endpoint): PendingRequest
    {
        return $this->http->request()->withOptions($endpoint->options)->asJson()->withHeaders(['Authorization' => 'Key '.self::secret($gateway)]);
    }

    /**
     * The redirect target must be https on khalti.com or a subdomain, or exactly the host and port of the `khalti.pay`
     * endpoint (khalti.com itself unless overridden, ADR 0016 §3). Anything PHP and browsers might parse differently is
     * refused outright: backslashes (browsers read them as "/"), whitespace and control characters, userinfo, other
     * explicit ports, and hosts that aren't plain DNS names (compared in lowercase).
     */
    private function isKhaltiUrl(string $url): bool
    {
        if ($url === '' || strlen($url) > 2048 || str_contains($url, '\\') || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            return false;
        }

        $parts = parse_url($url);

        if ($parts === false || ($parts['scheme'] ?? null) !== 'https' || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        $domain = strtolower((string) config('payments_nepal.khalti.payment_domain', 'khalti.com'));
        // An override the outbound policy refuses matches nothing; the khalti.com rule below still applies.
        try {
            $page = parse_url(Endpoints::for('khalti.pay', 'https://'.$domain)->baseUrl);
        } catch (OutboundAddressRefused) {
            $page = false;
        }

        if (is_array($page) && $host === strtolower((string) ($page['host'] ?? '')) && ($parts['port'] ?? null) === ($page['port'] ?? null)) {
            return true;
        }

        if (isset($parts['port']) || preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host) !== 1) {
            return false;
        }

        return $host === $domain || str_ends_with($host, '.'.$domain);
    }

    /** The pidx query value of a kept payment_url (Khalti's own format: `https://…/?pidx=<pidx>`). */
    private static function urlPidx(string $url): ?string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return is_string($query['pidx'] ?? null) ? $query['pidx'] : null;
    }

    private static function partialRefunds(): bool
    {
        return filter_var(config('payments_nepal.khalti.partial_refunds', false), FILTER_VALIDATE_BOOLEAN);
    }

    private static function isProviderRef(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{1,64}$/', $value) === 1;
    }

    private static function secret(GatewayConfig $gateway): string
    {
        $secret = $gateway->setting('secret_key', '');

        return is_string($secret) ? trim($secret) : '';
    }

    private static function intConfig(string $key, int $default): int
    {
        $value = config('payments_nepal.'.$key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }
}
