<?php

namespace Jiannius\Stripe\Helpers;

class Sample
{
    public static function build()
    {
        return [
            'customer' => 'cus_NzHqNbIaJ56Juq',
            'customer_email' => 'test@sign.up',
            'mode' => 'subscription',
            'metadata' => [
                'payment_id' => 1,
            ],
            'line_items' => [
                [
                    'quantity' => 1,
                    'price_data' => [
                        'currency' => 'USD',
                        'product_data' => [
                            'name' => 'HumbleBear Pro Plan Monthly',
                        ],
                        'unit_amount' => '1500',
                        'recurring' => [
                            'interval_count' => 1,
                            'interval' => 'month',
                        ],
                    ],
                ],
            ],
            'subscription_data' => [
                'metadata' => [
                    'payment_id' => 1,
                ],
            ],
            'success_url' => route('__stripe.success'),
            'cancel_url' => route('__stripe.cancel'),
        ];
    }
}