<?php

namespace Jiannius\Stripe\Tests;

use Jiannius\Stripe\Models\StripeWebhookEvent;
use Jiannius\Stripe\Stripe;

class IdempotencyHelpersTest extends TestCase
{
    public function test_reports_an_event_unprocessed_until_marked()
    {
        $stripe = new Stripe();
        $this->assertFalse($stripe->hasProcessedEvent('evt_1'));

        $stripe->markProcessedEvent('evt_1', 'invoice.paid');

        $this->assertTrue($stripe->hasProcessedEvent('evt_1'));
        $this->assertSame(1, StripeWebhookEvent::where('event_id', 'evt_1')->whereNotNull('processed_at')->count());
    }

    public function test_marking_same_event_twice_keeps_single_row()
    {
        $stripe = new Stripe();
        $stripe->markProcessedEvent('evt_2', 'invoice.paid');
        $stripe->markProcessedEvent('evt_2', 'invoice.paid');

        $this->assertSame(1, StripeWebhookEvent::where('event_id', 'evt_2')->count());
    }
}
