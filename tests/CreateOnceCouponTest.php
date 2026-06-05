<?php

namespace Jiannius\Stripe\Tests;

use Jiannius\Stripe\Stripe;
use Mockery;

class CreateOnceCouponTest extends TestCase
{
    public function test_creates_a_single_use_first_invoice_coupon_in_minor_units()
    {
        $coupons = Mockery::mock();
        $coupons->shouldReceive('create')
            ->once()
            ->with([
                'amount_off' => 2350,
                'currency' => 'myr',
                'duration' => 'once',
                'max_redemptions' => 1,
            ])
            ->andReturn((object) ['id' => 'coupon_test']);

        $client = Mockery::mock();
        $client->coupons = $coupons;

        $stripe = Mockery::mock(Stripe::class)->makePartial();
        $stripe->shouldReceive('getStripeClient')->andReturn($client);

        $this->assertSame('coupon_test', $stripe->createOnceCoupon(23.50, 'myr'));
    }

    public function test_converts_whole_number_amounts_correctly()
    {
        $coupons = Mockery::mock();
        $coupons->shouldReceive('create')
            ->once()
            ->with(Mockery::on(fn($args) => $args['amount_off'] === 3000))
            ->andReturn((object) ['id' => 'coupon_whole']);

        $client = Mockery::mock();
        $client->coupons = $coupons;

        $stripe = Mockery::mock(Stripe::class)->makePartial();
        $stripe->shouldReceive('getStripeClient')->andReturn($client);

        $this->assertSame('coupon_whole', $stripe->createOnceCoupon(30.0, 'usd'));
    }

    protected function tearDown() : void
    {
        Mockery::close();
        parent::tearDown();
    }
}
