<?php

namespace Jiannius\Stripe\Tests;

class WebhookRouteTest extends TestCase
{
    public function test_idempotency_middleware_is_attached_by_default()
    {
        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn($r) => $r->getName() === '__stripe.webhook');

        $this->assertNotNull($route);
        $this->assertContains(
            \Jiannius\Stripe\Middleware\EnsureWebhookIdempotency::class,
            $route->gatherMiddleware(),
        );
    }
}
