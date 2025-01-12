<?php

namespace Jiannius\Stripe;

use Jiannius\Stripe\Helpers\Sample;

class Stripe
{
    public $settings = [];

    public function setPublicKey($key)
    {
        $this->settings['public_key'] = $key;
        return $this;
    }

    public function setSecretKey($key)
    {
        $this->settings['secret_key'] = $key;
        return $this;
    }

    public function setWebhookSecret($key)
    {
        $this->settings['webhook_secret'] = $key;
        return $this;
    }

    public function getSettings($key = null)
    {
        $settings = [
            'public_key' => data_get($this->settings, 'public_key') ?? env('STRIPE_PUBLIC_KEY'),
            'secret_key' => data_get($this->settings, 'secret_key') ?? env('STRIPE_SECRET_KEY'),
            'webhook_secret' => data_get($this->settings, 'webhook_secret') ?? env('STRIPE_WEBHOOK_SECRET'),
        ];

        return $key ? data_get($settings, $key) : $settings;
    }

    public function getStripeClient()
    {
        return new \Stripe\StripeClient($this->getSettings('secret_key'));
    }

    public function getWebhookPayload()
    {
        $input = @file_get_contents('php://input');

        return json_decode($input, true);
    }

    public function getWebhookStatus()
    {
        $payload = $this->getWebhookPayload();
        $event = data_get($payload, 'type');

        if (!$this->validateWebhookPayload()) return null;

        $isRenew = in_array($event, [
            'invoice.paid',
            'invoice.payment_failed',
        ]) && data_get($payload, 'data.object.billing_reason') === 'subscription_cycle';

        $isFailed = in_array($event, [
            'checkout.session.expired',
            'checkout.session.async_payment_failed',
            'invoice.payment_failed',
        ]);

        $isSuccess = in_array($event, [
            'checkout.session.async_payment_succeeded', 
            'invoice.paid',
        ]) || (
            $event === 'checkout.session.completed'
            && data_get($payload, 'data.object.payment_status') === 'paid'
        );

        $isProcessing = $event === 'checkout.session.completed' 
            && data_get($payload, 'data.object.payment_status') !== 'paid';

        return collect([
            'renew-failed' => $isRenew && $isFailed,
            'renew-success' => $isRenew && $isSuccess,
            'failed' => $isFailed,
            'processing' => $isProcessing,
            'success' => $isSuccess,
        ])->filter()->keys()->first();
    }

    public function validateWebhookPayload() : bool
    {
        $input = @file_get_contents('php://input');
        $secret = $this->getSettings('webhook_secret');
        $sigheader = $_SERVER['HTTP_STRIPE_SIGNATURE'];

        try {
            $event = \Stripe\Webhook::constructEvent($input, $sigheader, $secret);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            $event = false;
        }

        if (!$event) {
            logger('Unable to validate signature with the webhook signing secret.');
            return false;
        }

        return true;
    }

    public function checkout($params)
    {
        $params = [
            ...$params,
            // change product amount to proper format (eg. 25.00 -> 2500)
            'line_items' => collect(data_get($params, 'line_items'))
                ->map(fn($item) =>
                    collect($item)->replaceRecursive([
                        'price_data' => [
                            'unit_amount' => str(data_get($item, 'price_data.unit_amount'))
                                ->replace('.', '')
                                ->replace(',', '')
                                ->toString(),
                        ],
                    ])->toArray()
                )
                ->toArray(),
            'success_url' => route('__stripe.success', data_get($params, 'metadata', [])),
            'cancel_url' => route('__stripe.cancel', data_get($params, 'metadata', [])),
        ];

        // create stripe checkout session
        $session = $this->getStripeClient()
            ->checkout
            ->sessions
            ->create($params);

        return redirect($session->url);
    }

    public function cancelSubscription($id)
    {
        return $this->getStripeClient()
            ->subscriptions
            ->cancel($id);
    }

    public function createWebhook() : mixed
    {
        $url = route('__stripe.webhook');
        $webhooks = $this->getStripeClient()->webhookEndpoints->all();
        
        // delete previously created webhook
        if ($webhook = collect($webhooks->data)->where('url', $url)->first()) {
            $this->getStripeClient()->webhookEndpoints->delete(data_get($webhook, 'id'));
        }

        $webhook = $this->getStripeClient()->webhookEndpoints->create([
            'url' => $url,
            'enabled_events' => [
                'checkout.session.async_payment_failed',
                'checkout.session.async_payment_succeeded',
                'checkout.session.completed',
                'checkout.session.expired',
            ],
        ]);

        return data_get($webhook, 'secret');
    }

    public function test()
    {
        try {
            $this->getStripeClient()->accounts->all();

            return [
                'success' => true,
                'error' => null,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function sample() : array
    {
        return Sample::build();
    }
}
