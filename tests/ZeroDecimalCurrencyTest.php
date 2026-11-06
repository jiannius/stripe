<?php

namespace Jiannius\Stripe\Tests;

use Jiannius\Stripe\Stripe;
use Mockery;

class ZeroDecimalCurrencyTest extends TestCase
{
    protected array $captured = [];

    protected function checkoutWith(mixed $amount, string $currency) : int
    {
        $sessions = Mockery::mock();
        $sessions->shouldReceive('create')
            ->once()
            ->andReturnUsing(function ($params) {
                $this->captured = $params;

                return (object) ['id' => 'cs_test', 'url' => 'https://example.test'];
            });

        $client = Mockery::mock();
        $client->checkout = (object) ['sessions' => $sessions];

        $stripe = Mockery::mock(Stripe::class)->makePartial();
        $stripe->shouldReceive('getStripeClient')->andReturn($client);

        $stripe->checkoutSession([
            'mode' => 'payment',
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => $currency,
                    'product_data' => ['name' => 'Item'],
                    'unit_amount' => $amount,
                ],
            ]],
        ]);

        return $this->captured['line_items'][0]['price_data']['unit_amount'];
    }

    public function test_zero_decimal_currency_is_not_multiplied()
    {
        $this->assertSame(1000, $this->checkoutWith('1,000.00', 'JPY'));
    }

    public function test_two_decimal_currency_is_multiplied()
    {
        $this->assertSame(1050, $this->checkoutWith('10.50', 'MYR'));
    }

    public function test_thousands_separator_is_stripped()
    {
        $this->assertSame(123456, $this->checkoutWith('1,234.56', 'MYR'));
    }

    public function test_integer_input_passes_through()
    {
        $this->assertSame(1050, $this->checkoutWith(1050, 'MYR'));
        $this->assertSame(1050, $this->checkoutWith('1050', 'MYR'));
    }

    public function test_to_minor_units_is_case_insensitive()
    {
        $stripe = new Stripe;

        $this->assertSame(500, $stripe->toMinorUnits('500', 'jpy'));
        $this->assertSame(500, $stripe->toMinorUnits('500', 'JPY'));
        $this->assertSame(2350, $stripe->toMinorUnits(23.5, 'myr'));
        $this->assertSame(2350, $stripe->toMinorUnits(23.5, 'MYR'));
        $this->assertSame(123456, $stripe->toMinorUnits('1,234.56', 'usd'));
    }

    public function test_to_minor_units_covers_stripe_zero_decimal_list()
    {
        $stripe = new Stripe;

        foreach (['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'] as $code) {
            $this->assertSame(500, $stripe->toMinorUnits(500, $code), $code);
        }
    }

    public function test_isk_ugx_huf_twd_keep_two_decimal_api_form()
    {
        $stripe = new Stripe;

        foreach (['ISK', 'UGX', 'HUF', 'TWD'] as $code) {
            $this->assertSame(500, $stripe->toMinorUnits(5, $code), $code);
        }
    }

    public function test_create_once_coupon_zero_decimal_currency()
    {
        $coupons = Mockery::mock();
        $coupons->shouldReceive('create')
            ->once()
            ->with(Mockery::on(fn($args) => $args['amount_off'] === 500 && $args['currency'] === 'jpy'))
            ->andReturn((object) ['id' => 'coupon_jpy']);

        $client = Mockery::mock();
        $client->coupons = $coupons;

        $stripe = Mockery::mock(Stripe::class)->makePartial();
        $stripe->shouldReceive('getStripeClient')->andReturn($client);

        $this->assertSame('coupon_jpy', $stripe->createOnceCoupon(500, 'jpy'));
    }

    protected function tearDown() : void
    {
        Mockery::close();
        parent::tearDown();
    }
}
