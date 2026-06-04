<?php

namespace Jiannius\Stripe\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureWebhookIdempotency
{
    public function handle(Request $request, Closure $next)
    {
        $stripe = app('stripe');
        $event = $stripe->getValidatedEvent();

        // Unverified / malformed → reject so a spoofed body is never processed.
        if (!$event) {
            return response('Invalid signature', 400);
        }

        $eventId = $event->id;

        if ($stripe->hasProcessedEvent($eventId)) {
            return response('OK');
        }

        $stripe->recordEvent($eventId, $event->type ?? null);

        $response = $next($request);

        // Record-on-success only: a non-2xx leaves processed_at null so Stripe retries.
        if ($response->isSuccessful()) {
            $stripe->markProcessedEvent($eventId, $event->type ?? null);
        }

        return $response;
    }
}
