<?php

namespace Jiannius\Stripe\Tests;

use Illuminate\Support\Facades\Route;
use Jiannius\Stripe\Middleware\EnsureWebhookIdempotency;
use Mockery;

class WebhookIdempotencyKernelTest extends TestCase
{
    public function test_duplicate_delivery_is_short_circuited_through_the_kernel()
    {
        // A real validated event, returned by the bound stripe singleton.
        $stripe = Mockery::mock(\Jiannius\Stripe\Stripe::class)->makePartial();
        $stripe->shouldReceive('getValidatedEvent')
            ->andReturn(\Stripe\Event::constructFrom(['id' => 'evt_kernel', 'type' => 'invoice.paid']));
        $this->app->instance('stripe', $stripe);

        // A throwaway route exercising the middleware through the full HTTP stack.
        $GLOBALS['__kernel_hits'] = 0;
        Route::withoutMiddleware('web')->post('/__test/webhook', function () {
            $GLOBALS['__kernel_hits']++;
            return response('OK');
        })->middleware(EnsureWebhookIdempotency::class);

        $this->post('/__test/webhook')->assertOk();   // first: handler runs, event marked
        $this->post('/__test/webhook')->assertOk();   // second: short-circuited by middleware

        $this->assertSame(1, $GLOBALS['__kernel_hits']);
        unset($GLOBALS['__kernel_hits']);
    }

    protected function tearDown() : void
    {
        Mockery::close();
        parent::tearDown();
    }
}
