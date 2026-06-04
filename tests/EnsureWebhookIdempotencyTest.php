<?php

namespace Jiannius\Stripe\Tests;

use Illuminate\Http\Request;
use Jiannius\Stripe\Middleware\EnsureWebhookIdempotency;
use Jiannius\Stripe\Models\StripeWebhookEvent;
use Mockery;

class EnsureWebhookIdempotencyTest extends TestCase
{
    private function fakeStripeReturning(?\Stripe\Event $event)
    {
        $stripe = Mockery::mock(\Jiannius\Stripe\Stripe::class)->makePartial();
        $stripe->shouldReceive('getValidatedEvent')->andReturn($event);
        $this->app->instance('stripe', $stripe);
        return $stripe;
    }

    private function event(string $id, string $type = 'invoice.paid') : \Stripe\Event
    {
        return \Stripe\Event::constructFrom(['id' => $id, 'type' => $type]);
    }

    public function test_invalid_signature_returns_400()
    {
        $this->fakeStripeReturning(null);

        $response = (new EnsureWebhookIdempotency())->handle(
            Request::create('/__stripe/webhook', 'POST'),
            fn() => response('OK'),
        );

        $this->assertSame(400, $response->getStatusCode());
    }

    public function test_new_event_runs_next_and_marks_processed()
    {
        $this->fakeStripeReturning($this->event('evt_new'));
        $called = 0;

        $response = (new EnsureWebhookIdempotency())->handle(
            Request::create('/__stripe/webhook', 'POST'),
            function () use (&$called) { $called++; return response('OK'); },
        );

        $this->assertSame(1, $called);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue(app('stripe')->hasProcessedEvent('evt_new'));
    }

    public function test_duplicate_processed_event_short_circuits()
    {
        $this->fakeStripeReturning($this->event('evt_dup'));
        StripeWebhookEvent::create(['event_id' => 'evt_dup', 'type' => 'invoice.paid', 'processed_at' => now()]);
        $called = 0;

        $response = (new EnsureWebhookIdempotency())->handle(
            Request::create('/__stripe/webhook', 'POST'),
            function () use (&$called) { $called++; return response('OK'); },
        );

        $this->assertSame(0, $called);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_failed_handler_leaves_event_unprocessed()
    {
        $this->fakeStripeReturning($this->event('evt_fail'));

        $response = (new EnsureWebhookIdempotency())->handle(
            Request::create('/__stripe/webhook', 'POST'),
            fn() => response('boom', 500),
        );

        $this->assertSame(500, $response->getStatusCode());
        $this->assertFalse(app('stripe')->hasProcessedEvent('evt_fail'));
    }

    protected function tearDown() : void
    {
        Mockery::close();
        parent::tearDown();
    }
}
