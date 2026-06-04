<?php

namespace Jiannius\Stripe\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureWebhookIdempotency
{
    /**
     * Best-effort idempotency gate for Stripe webhook deliveries. Dedupes
     * retried/duplicate events via the stripe_webhook_events ledger using
     * record-on-success semantics. Two truly-simultaneous deliveries can still
     * both pass before either is marked processed — the consuming app's domain
     * unique constraint (e.g. a Stripe invoice id) is the authoritative backstop.
     */
    public function handle(Request $request, Closure $next)
    {
        $stripe = app('stripe');
        $event = $stripe->getValidatedEvent();

        // Unverified / malformed → reject so a spoofed body is never processed.
        if (!$event) {
            return response('Invalid signature', 400);
        }

        $record = $stripe->recordEvent($event->id, $event->type ?? null);

        // Already fully processed by a prior delivery → skip without re-running.
        if ($record->processed_at) {
            return response('OK');
        }

        $response = $next($request);

        // Record-on-success only: a non-2xx leaves processed_at null so Stripe retries.
        if ($response->isSuccessful()) {
            $stripe->markProcessedEvent($event->id, $event->type ?? null);
        }

        return $response;
    }
}
