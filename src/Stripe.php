<?php

namespace Jiannius\Stripe;

use Jiannius\Stripe\Helpers\Sample;
use Jiannius\Stripe\Models\StripeWebhookEvent;

class Stripe
{
    /** @var array<string, mixed> Runtime-overridden settings; falls back to `config('services.stripe.*')`. */
    public $settings = [];

    /** @var \Stripe\Event|null Cached event from the verified webhook payload for the current request. */
    protected ?\Stripe\Event $validatedEvent = null;

    /** @var bool Whether signature verification has been attempted on the current request. */
    protected bool $validationAttempted = false;

    /**
     * Override the Stripe publishable key for the current instance.
     *
     * @param  string  $key
     * @return $this
     */
    public function setPublicKey($key)
    {
        $this->settings['public_key'] = $key;
        return $this;
    }

    /**
     * Override the Stripe secret key for the current instance.
     *
     * @param  string  $key
     * @return $this
     */
    public function setSecretKey($key)
    {
        $this->settings['secret_key'] = $key;
        return $this;
    }

    /**
     * Override the Stripe webhook signing secret for the current instance.
     *
     * @param  string  $key
     * @return $this
     */
    public function setWebhookSecret($key)
    {
        $this->settings['webhook_secret'] = $key;
        return $this;
    }

    /**
     * Resolve Stripe settings, preferring instance-level overrides over `config('services.stripe.*')`.
     *
     * @param  string|null  $key  Optional setting name: 'public_key', 'secret_key', or 'webhook_secret'.
     * @return mixed  The full settings array when `$key` is null, otherwise the resolved value (or null).
     */
    public function getSettings($key = null)
    {
        $settings = [
            'public_key' => data_get($this->settings, 'public_key') ?? config('services.stripe.public_key'),
            'secret_key' => data_get($this->settings, 'secret_key') ?? config('services.stripe.secret_key'),
            'webhook_secret' => data_get($this->settings, 'webhook_secret') ?? config('services.stripe.webhook_secret'),
        ];

        return $key ? data_get($settings, $key) : $settings;
    }

    /**
     * Build a Stripe SDK client using the resolved secret key.
     *
     * @return \Stripe\StripeClient
     */
    public function getStripeClient()
    {
        return new \Stripe\StripeClient($this->getSettings('secret_key'));
    }

    /**
     * Return the signature-verified Stripe event for the current webhook request.
     *
     * Verification runs at most once per request; the result is cached. Returns
     * null when the `Stripe-Signature` header is missing or invalid, or when the
     * body is malformed JSON.
     *
     * @return \Stripe\Event|null
     */
    public function getValidatedEvent() : ?\Stripe\Event
    {
        if ($this->validationAttempted) {
            return $this->validatedEvent;
        }

        $this->validationAttempted = true;

        $secret = $this->getSettings('webhook_secret');
        $sigheader = (string) (request()->header('Stripe-Signature') ?? '');

        try {
            $this->validatedEvent = \Stripe\Webhook::constructEvent(
                $this->getRawWebhookInput(),
                $sigheader,
                $secret,
            );
        } catch (\Stripe\Exception\SignatureVerificationException | \UnexpectedValueException $e) {
            logger()->warning('Unable to validate Stripe webhook signature.', [
                'error' => $e->getMessage(),
            ]);
        }

        return $this->validatedEvent;
    }

    /**
     * Whether the current request carries a Stripe-signed webhook payload.
     *
     * Thin wrapper around {@see getValidatedEvent()} for callers that only need
     * a boolean check.
     *
     * @return bool
     */
    public function validateWebhookPayload() : bool
    {
        return $this->getValidatedEvent() !== null;
    }

    /**
     * Decode the raw webhook request body as an associative array.
     *
     * Does **not** verify the Stripe signature. For trusted access prefer
     * {@see getValidatedEvent()} and read fields off the typed `\Stripe\Event`.
     *
     * @return array<string, mixed>|null
     */
    public function getWebhookPayload()
    {
        return json_decode($this->getRawWebhookInput(), true);
    }

    /**
     * Classify the current webhook event into a coarse status string.
     *
     * Returns one of `'success'`, `'renew-success'`, `'renew-failed'`,
     * `'processing'`, `'failed'`, or `null` if the signature does not verify.
     * See the README's status reference for the event-to-status mapping.
     *
     * @return string|null
     */
    public function getWebhookStatus()
    {
        $event = $this->getValidatedEvent();

        if (!$event) return null;

        $type = $event->type;
        $object = $event->data->object;

        $isRenew = in_array($type, [
            'invoice.paid',
            'invoice.payment_failed',
        ]) && data_get($object, 'billing_reason') === 'subscription_cycle';

        $isFailed = in_array($type, [
            'checkout.session.expired',
            'checkout.session.async_payment_failed',
            'invoice.payment_failed',
        ]);

        $isSuccess = in_array($type, [
            'checkout.session.async_payment_succeeded',
            'invoice.paid',
        ]) || (
            $type === 'checkout.session.completed'
            && data_get($object, 'payment_status') === 'paid'
        );

        $isProcessing = $type === 'checkout.session.completed'
            && data_get($object, 'payment_status') !== 'paid';

        return collect([
            'renew-failed' => $isRenew && $isFailed,
            'renew-success' => $isRenew && $isSuccess,
            'failed' => $isFailed,
            'processing' => $isProcessing,
            'success' => $isSuccess,
        ])->filter()->keys()->first();
    }

    /**
     * Create a Stripe Checkout session and return a redirect response to its URL.
     *
     * `price_data.unit_amount` values are normalized to the smallest currency
     * unit: decimal strings are multiplied by 100, integer-like values pass
     * through unchanged. Line items that use a Stripe Price ID (`price` key,
     * no `price_data`) are forwarded untouched. `success_url` and `cancel_url`
     * are always overwritten with the package's named routes; any `metadata`
     * is forwarded as route parameters on those URLs.
     *
     * @param  array<string, mixed>  $params  Forwarded to `Stripe\StripeClient::checkout->sessions->create()`.
     * @return \Illuminate\Http\RedirectResponse
     */
    public function checkout($params)
    {
        return redirect($this->checkoutSession($params)->url);
    }

    /**
     * Create a Checkout Session and return the Session object (caller decides how to
     * redirect and what to persist). Same param normalization as checkout().
     *
     * @param  array<string, mixed>  $params
     * @return \Stripe\Checkout\Session
     */
    public function checkoutSession($params)
    {
        $params = [
            ...$params,
            'line_items' => collect(data_get($params, 'line_items'))
                ->map(fn($item) => $this->normalizeLineItem($item))
                ->toArray(),
            'success_url' => route('__stripe.success', data_get($params, 'metadata', [])),
            'cancel_url' => route('__stripe.cancel', data_get($params, 'metadata', [])),
        ];

        return $this->getStripeClient()
            ->checkout
            ->sessions
            ->create($params);
    }

    /**
     * Retrieve a Checkout Session by id (for reconciling payment state).
     *
     * @param  string  $id  Stripe Checkout Session id (cs_...).
     * @return \Stripe\Checkout\Session
     */
    public function retrieveCheckoutSession(string $id)
    {
        return $this->getStripeClient()->checkout->sessions->retrieve($id, ['expand' => ['payment_intent']]);
    }

    /**
     * Cancel a subscription immediately.
     *
     * To cancel at the end of the current period instead, use
     * `getStripeClient()->subscriptions->update($id, ['cancel_at_period_end' => true])`.
     *
     * @param  string  $id  Stripe subscription ID (e.g. `sub_xxx`).
     * @return \Stripe\Subscription
     */
    public function cancelSubscription($id)
    {
        return $this->getStripeClient()
            ->subscriptions
            ->cancel($id);
    }

    /**
     * Create a single-use, first-invoice-only discount coupon.
     *
     * The coupon holds no per-customer state — it is a throwaway discount
     * definition applied to one Checkout Session's first invoice.
     *
     * @param  float   $amountOff  Discount in the major unit (e.g. 23.50).
     * @param  string  $currency   ISO currency; must match the subscription's currency.
     * @return string  The created coupon id.
     */
    public function createOnceCoupon(float $amountOff, string $currency) : string
    {
        $coupon = $this->getStripeClient()->coupons->create([
            'amount_off' => (int) round($amountOff * 100),
            'currency' => $currency,
            'duration' => 'once',
            'max_redemptions' => 1,
        ]);

        return $coupon->id;
    }

    /**
     * (Re)register a Stripe webhook endpoint pointing at this package's webhook route.
     *
     * Walks all existing endpoints (paginated), deletes any whose URL matches
     * the package route, then creates a fresh endpoint subscribed to the
     * events this package can interpret. Each call rotates the signing secret;
     * persist the return value as `STRIPE_WEBHOOK_SECRET`.
     *
     * @return string|null  The new webhook signing secret.
     */
    public function createWebhook() : mixed
    {
        $url = route('__stripe.webhook');
        $client = $this->getStripeClient();

        foreach ($client->webhookEndpoints->all()->autoPagingIterator() as $endpoint) {
            if (data_get($endpoint, 'url') === $url) {
                $client->webhookEndpoints->delete(data_get($endpoint, 'id'));
            }
        }

        $webhook = $client->webhookEndpoints->create([
            'url' => $url,
            'enabled_events' => [
                'checkout.session.async_payment_failed',
                'checkout.session.async_payment_succeeded',
                'checkout.session.completed',
                'checkout.session.expired',
                'invoice.paid',
                'invoice.payment_failed',
            ],
        ]);

        return data_get($webhook, 'secret');
    }

    /**
     * Smoke-test the configured secret key against the Stripe API.
     *
     * @return array{success: bool, error: string|null}
     */
    public function test()
    {
        try {
            $this->getStripeClient()->accounts->all();

            return [
                'success' => true,
                'error' => null,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Return a sample Checkout Session payload for development and fixtures.
     *
     * @return array<string, mixed>
     */
    public function sample() : array
    {
        return Sample::build();
    }

    /**
     * Whether a Stripe webhook event id has already been fully processed.
     */
    public function hasProcessedEvent(string $eventId) : bool
    {
        return StripeWebhookEvent::where('event_id', $eventId)
            ->whereNotNull('processed_at')
            ->exists();
    }

    /**
     * Record an event row if absent (idempotent). Does not set processed_at.
     * A null $type never overwrites an existing value.
     */
    public function recordEvent(string $eventId, ?string $type = null) : StripeWebhookEvent
    {
        return StripeWebhookEvent::firstOrCreate(
            ['event_id' => $eventId],
            array_filter(['type' => $type], fn($v) => $v !== null),
        );
    }

    /**
     * Mark an event processed (creating its row if absent). A null $type never
     * overwrites an existing value.
     */
    public function markProcessedEvent(string $eventId, ?string $type = null) : void
    {
        StripeWebhookEvent::updateOrCreate(
            ['event_id' => $eventId],
            array_filter(['type' => $type, 'processed_at' => now()], fn($v) => $v !== null),
        );
    }

    /**
     * Read the raw HTTP request body. Cached by Laravel's Request internally.
     *
     * @return string
     */
    protected function getRawWebhookInput() : string
    {
        return (string) request()->getContent();
    }

    /**
     * Normalize a single Checkout line item by converting `price_data.unit_amount`
     * to the smallest currency unit. Items without `price_data` pass through.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    protected function normalizeLineItem(array $item) : array
    {
        if (!isset($item['price_data']['unit_amount'])) {
            return $item;
        }

        $item['price_data']['unit_amount'] = $this->toStripeAmount($item['price_data']['unit_amount']);

        return $item;
    }

    /**
     * Convert a monetary amount to an integer in the smallest currency unit.
     *
     * Commas are stripped (thousands separators). Decimal inputs are
     * multiplied by 100 and rounded; integer-like inputs are cast as-is.
     *
     * @param  mixed  $amount
     * @return int
     */
    protected function toStripeAmount($amount) : int
    {
        $clean = str_replace(',', '', (string) $amount);

        if (str_contains($clean, '.')) {
            return (int) round(((float) $clean) * 100);
        }

        return (int) $clean;
    }
}
