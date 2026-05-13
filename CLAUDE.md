# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A thin Laravel wrapper around `stripe/stripe-php` (Stripe SDK v10). Published as the Composer package `jiannius/stripe` and consumed by host Laravel apps. PSR-4: `Jiannius\Stripe\` → `src/`.

## Commands

- Install deps: `composer install`
- No test, lint, or build scripts are defined in `composer.json`. `orchestra/testbench` is listed as a dev dependency but there is no `tests/` directory or PHPUnit config yet — adding tests means setting that scaffolding up first.

## Architecture

**Service provider auto-discovery.** `composer.json` registers `Jiannius\Stripe\StripeServiceProvider` under `extra.laravel.providers`. The provider does two things in `boot()`:
1. Loads `routes/web.php` into the host app's route table.
2. Binds the container key `stripe` to a new `Jiannius\Stripe\Stripe` instance — so host code uses `app('stripe')->...`.

**Settings cascade.** `Stripe::getSettings()` reads instance-level keys set via `setPublicKey()` / `setSecretKey()` / `setWebhookSecret()` and falls back to `config('services.stripe.{public_key,secret_key,webhook_secret})`. Always go through `getSettings()` rather than reading config directly — both code paths must keep working.

**Routes expect a host-app controller.** `routes/web.php` registers three named routes under the `__stripe` prefix (`__stripe.success`, `__stripe.cancel`, `__stripe.webhook`) all pointing at `App\Http\Controllers\StripeController`. That controller lives in the consuming application, *not* in this package — the package defines the route shape and contract; the host implements the handlers (typically calling `app('stripe')->getWebhookStatus()` etc.). Webhook routes use `withoutMiddleware('web')` so CSRF is skipped.

**Checkout amount mutation.** `Stripe::checkout()` strips `.` and `,` from every `line_items.*.price_data.unit_amount` before forwarding to Stripe (so `25.00` becomes `2500`). Callers pass human-formatted decimals; Stripe needs integer cents. Don't double-convert. The method also overwrites any caller-supplied `success_url`/`cancel_url` with the package's named routes, passing the request's `metadata` through as route parameters.

**Webhook status mapping.** `getWebhookStatus()` first validates the signature via `validateWebhookPayload()` (using `Stripe\Webhook::constructEvent` against `HTTP_STRIPE_SIGNATURE` and the configured `webhook_secret`), then collapses a set of Stripe event types into one of: `renew-failed`, `renew-success`, `failed`, `processing`, `success`, or `null`. `invoice.paid`/`invoice.payment_failed` are treated as renewals only when `billing_reason === 'subscription_cycle'`. When extending, preserve precedence — the first truthy key in the collection wins.

**Webhook registration is destructive.** `createWebhook()` lists all existing endpoints, deletes any whose URL matches `route('__stripe.webhook')`, then creates a fresh one. The returned secret is what should be stored as `webhook_secret`. Re-running rotates the secret.

**Helpers directory.** `src/Helpers/` is for static utility classes (see `Sample.php`, exposed via `Stripe::sample()`, which returns a fixture payload for testing checkout flows). Static `build()`-style methods are the pattern.
