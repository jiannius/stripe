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

    public function test_marking_processed_with_null_type_preserves_existing_type()
    {
        $stripe = new Stripe();
        $stripe->recordEvent('evt_type', 'invoice.paid');   // row now has type

        $stripe->markProcessedEvent('evt_type', null);        // null must not clobber

        $row = StripeWebhookEvent::where('event_id', 'evt_type')->first();
        $this->assertSame('invoice.paid', $row->type);
        $this->assertNotNull($row->processed_at);
    }
}
