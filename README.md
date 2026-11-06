# Jiannius Stripe SDK Wrapper

A thin Laravel wrapper around the official Stripe PHP SDK. Handles Checkout Sessions, subscription webhooks, and signature verification with sensible defaults.

## Requirements

- PHP 8.2+ (8.3+ for Laravel 13)
- Laravel 10, 11, 12, or 13
- `stripe/stripe-php` 10 through 17

## Installation

```bash
composer require jiannius/stripe:^1.2
```

The service provider is auto-discovered via `extra.laravel.providers`.

## Configuration

Add Stripe keys to `config/services.php`:

```php
'stripe' => [
    'public_key'     => env('STRIPE_PUBLIC_KEY'),
    'secret_key'     => env('STRIPE_SECRET_KEY'),
    'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
],
```

And to `.env`:

```env
STRIPE_PUBLIC_KEY=pk_live_...
STRIPE_SECRET_KEY=sk_live_...
STRIPE_WEBHOOK_SECRET=whsec_...
```

Never commit live keys to source control. Use environment variables or a secrets vault. For production traffic, prefer a [restricted API key](https://docs.stripe.com/keys/restricted-api-keys) (`rk_*`) over a full secret key.

## Implement the host controller

The package registers three routes that point at `App\Http\Controllers\StripeController`. You must implement this controller in your application — the package supplies the routes and contract, your app supplies the behavior.

| Route                  | Name              | Method | Purpose                                      |
|------------------------|-------------------|--------|----------------------------------------------|
| `/__stripe/success`    | `__stripe.success`| GET    | Stripe redirects users here after payment    |
| `/__stripe/cancel`     | `__stripe.cancel` | GET    | Stripe redirects users here on cancel        |
| `/__stripe/webhook`    | `__stripe.webhook`| POST   | Stripe posts events here                     |

Minimal controller:

```php
namespace App\Http\Controllers;

use Illuminate\Http\Request;

class StripeController extends Controller
{
    public function success(Request $request) { /* ... your business logic ... */ }
    public function cancel(Request $request)  { /* ... your business logic ... */ }
    public function webhook(Request $request) { /* see "Handling webhooks" below */ }
}
```

## Register your webhook endpoint with Stripe

Once your app is deployed and the controller is in place, register the webhook endpoint:

```php
$webhookSecret = app('stripe')->createWebhook();
```

This deletes any existing endpoint pointing at the same URL, creates a fresh one subscribed to the events the package handles, and returns the new signing secret. Store it as `STRIPE_WEBHOOK_SECRET` — every call to `createWebhook()` rotates the secret.

Events subscribed:

- `checkout.session.completed`
- `checkout.session.expired`
- `checkout.session.async_payment_succeeded`
- `checkout.session.async_payment_failed`
- `invoice.paid`
- `invoice.payment_failed`

If you'd rather manage the endpoint manually in the Stripe Dashboard, enable the same six events.

## Creating a Checkout session

```php
return app('stripe')->checkout([
    'mode'     => 'subscription',
    'customer' => $user->stripe_customer_id,
    'metadata' => ['order_id' => $order->id],
    'line_items' => [[
        'quantity' => 1,
        'price_data' => [
            'currency' => 'USD',
            'product_data' => ['name' => 'Pro Plan'],
            'unit_amount' => '15.00',
            'recurring' => ['interval' => 'month', 'interval_count' => 1],
        ],
    ]],
]);
```

Returns a Laravel `RedirectResponse` to the Stripe-hosted checkout page.

### `unit_amount` accepts two forms

| Input            | Result | Notes                                   |
|------------------|--------|-----------------------------------------|
| `"15.00"`, `"15.5"` | `1500`, `1550` | Decimal values are converted to the currency's minor unit (x100 for most currencies) |
| `1500`, `"1500"` | `1500` | Integer (smallest currency unit) passed through |

Conversion is currency-aware: for zero-decimal currencies (JPY, KRW, etc.) a decimal such as `"1,000.00"` becomes `1000`, not `100000`. Integer inputs are never converted.

To convert a major-unit amount yourself, use `toMinorUnits()`:

```php
app('stripe')->toMinorUnits('1,234.56', 'MYR'); // 123456
app('stripe')->toMinorUnits('1,000.00', 'jpy'); // 1000
```

The input is always treated as a major-unit amount, and the currency compare is case-insensitive. `createOnceCoupon()` uses the same conversion.

### Using an existing Stripe Price ID

Pass `price` instead of `price_data` and the package leaves the line item untouched:

```php
'line_items' => [[
    'quantity' => 1,
    'price'    => 'price_xxx',
]],
```

### Metadata flows into success/cancel URLs

Anything you pass under `metadata` is forwarded as route parameters on the `__stripe.success` and `__stripe.cancel` URLs, so your controller can read them off the request.

## Handling webhooks

```php
public function webhook(Request $request)
{
    $stripe = app('stripe');
    $status = $stripe->getWebhookStatus();

    if ($status === null) {
        return response('Invalid signature', 400);
    }

    $event   = $stripe->getValidatedEvent();   // \Stripe\Event
    $object  = $event->data->object;           // \Stripe\Invoice, CheckoutSession, ...

    if (\App\Models\ProcessedWebhook::where('event_id', $event->id)->exists()) {
        return response('Already processed', 200);
    }

    match ($status) {
        'success'       => /* one-time / first-period payment succeeded */,
        'renew-success' => /* subscription renewed for another period */,
        'renew-failed'  => /* renewal payment failed; Stripe may retry */,
        'processing'    => /* async payment method still settling */,
        'failed'        => /* checkout expired or async payment failed */,
    };

    \App\Models\ProcessedWebhook::create(['event_id' => $event->id]);

    return response('OK', 200);
}
```

### Status reference

| Status          | Triggered by                                                                            | Meaning                                                                 |
|-----------------|-----------------------------------------------------------------------------------------|-------------------------------------------------------------------------|
| `success`       | `checkout.session.completed` (paid), `checkout.session.async_payment_succeeded`, `invoice.paid` (non-cycle) | One-time payment or first subscription invoice succeeded                |
| `renew-success` | `invoice.paid` with `billing_reason=subscription_cycle`                                 | Subscription renewed at period end                                      |
| `renew-failed`  | `invoice.payment_failed` with `billing_reason=subscription_cycle`                       | Renewal payment failed (Stripe may retry per your dunning settings)     |
| `processing`    | `checkout.session.completed` with `payment_status != paid`                              | Customer used an async method (e.g. ACH) — money hasn't cleared yet     |
| `failed`        | `checkout.session.expired`, `checkout.session.async_payment_failed`                     | Checkout expired or the async payment failed                            |
| `null`          | Signature mismatch / malformed payload                                                  | Treat as untrusted; return a 4xx and do not process                     |

### Idempotency

Stripe retries failed webhook deliveries for up to 3 days. Always dedupe by `$event->id` (format `evt_xxx`) before acting — otherwise a single renewal can mark a customer's account renewed multiple times. Smart Retries can also legitimately send a `renew-failed` *followed by* a `renew-success` for the same invoice; your handler should treat them as a sequence rather than as conflicting outcomes.

### Accessing the validated event vs. the raw payload

| Method                  | Returns                              | When to use                                                |
|-------------------------|--------------------------------------|------------------------------------------------------------|
| `getWebhookStatus()`    | `string\|null`                       | Classify the event into a small set of statuses            |
| `getValidatedEvent()`   | `\Stripe\Event\|null`                | Typed access to event/invoice/subscription/customer IDs and any field |
| `getWebhookPayload()`   | `array\|null` (unvalidated)          | Debugging only. **Do not act on this without also calling `validateWebhookPayload()` or `getValidatedEvent()`.** |

All three share an internal cache — the signature is verified once per request.

## Cancelling subscriptions

```php
app('stripe')->cancelSubscription($subscriptionId);
```

Cancels immediately. To cancel at period end (so the customer keeps access until the period they've already paid for ends), call the SDK directly:

```php
app('stripe')->getStripeClient()->subscriptions->update($subscriptionId, [
    'cancel_at_period_end' => true,
]);
```

## Runtime configuration

For multi-tenant apps that need to swap Stripe accounts at runtime, override the configured keys before any call:

```php
app('stripe')
    ->setSecretKey($tenant->stripe_secret_key)
    ->setPublicKey($tenant->stripe_public_key)
    ->setWebhookSecret($tenant->stripe_webhook_secret)
    ->checkout([ /* ... */ ]);
```

Settings cascade: explicit setters take precedence over `config('services.stripe.*')`.

## Testing connectivity

```php
['success' => $ok, 'error' => $message] = app('stripe')->test();
```

Calls `accounts->all()` on the configured secret key. Useful as a settings-page health check.

## Security checklist

- [ ] Store `STRIPE_SECRET_KEY` and `STRIPE_WEBHOOK_SECRET` in environment variables or a secrets vault, never in source.
- [ ] Use a [restricted API key](https://docs.stripe.com/keys/restricted-api-keys) (`rk_*`) in place of the full secret key.
- [ ] Add a pre-commit hook to block `sk_live_` / `rk_live_` patterns.
- [ ] Use separate keys per environment (production / staging / local).
- [ ] Rotate keys when teammates with access leave.
- [ ] Optionally [allowlist Stripe's webhook IPs](https://docs.stripe.com/ips) on your edge as defense in depth.

## Versioning

| Tag    | Laravel | `stripe-php`     | Notes                                                                 |
|--------|---------|------------------|-----------------------------------------------------------------------|
| `v0.1` | 10      | `^10.0`          | Pre-Laravel 13 baseline                                               |
| `v1.0` | 10–13   | `^10.0`          | Laravel 13 dependency widening                                        |
| `v1.1` | 10–13   | `>=10.0 <18.0`   | Webhook + checkout bug fixes; widened `stripe-php`                    |
| `v1.2` | 10–13   | `>=10.0 <18.0`   | Adds `getValidatedEvent()`; consolidated webhook event parsing        |
| `v2.3` | 10–13   | `>=10.0 <18.0`   | Zero-decimal currency support; adds `toMinorUnits()`                  |

### Upgrading from v0.1 / v1.0

No code changes required — every public method is preserved. Two things to do after upgrading:

1. **Re-register the webhook** so the renewal events are delivered:
   ```php
   $newSecret = app('stripe')->createWebhook();
   // update STRIPE_WEBHOOK_SECRET in your environment
   ```
   Or in the Stripe Dashboard, add `invoice.paid` and `invoice.payment_failed` to the existing endpoint.
2. **Adopt `getValidatedEvent()`** in your webhook controller to drop the second JSON parse and to get the event ID for idempotency.

## Webhook idempotency (default-on)

The package dedupes Stripe's retried/duplicate webhook deliveries via the
`stripe_webhook_events` ledger and the `EnsureWebhookIdempotency` middleware,
attached automatically to the `__stripe.webhook` route. Each delivery is
processed at most once; a handler that returns a non-2xx leaves the event
unrecorded so Stripe's retry reprocesses it. This is a best-effort gate — the
consuming app's domain unique constraint is the authoritative backstop against
duplicate side effects.

Run migrations after upgrading (the package ships `stripe_webhook_events`).

Config (publish with `--tag=stripe-config`): set `stripe.webhook.idempotent`
to `false` to disable, or `stripe.webhook.ip_allowlist` to `true` to also
reject non-Stripe IPs.

## License

[MIT](LICENSE.md).
