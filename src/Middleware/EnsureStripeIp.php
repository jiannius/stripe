<?php

namespace Jiannius\Stripe\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureStripeIp
{
    public function handle(Request $request, Closure $next)
    {
        $allowed = (array) config('stripe.webhook.ips', []);

        if ($allowed && !in_array($request->ip(), $allowed, true)) {
            return response('Forbidden', 403);
        }

        return $next($request);
    }
}
