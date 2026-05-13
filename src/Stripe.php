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
            'public_key' => data_get($this->settings, 'public_key') ?? config('services.stripe.public_key'),
            'secret_key' => data_get($this->settings, 'secret_key') ?? config('services.stripe.secret_key'),
            'webhook_secret' => data_get($this->settings, 'webhook_secret') ?? config('services.stripe.webhook_secret'),
        ];

        return $key ? data_get($settings, $key) : $settings;
    }

    public function getStripeClient()
    {
        return new \Stripe\StripeClient($this->getSettings('secret_key'));
    }

    public function getWebhookPayload()
    {
        return json_decode($this->getRawWebhookInput(), true);
    }

    public function getWebhookStatus()
    {
        if (!$this->validateWebhookPayload()) return null;

        $payload = $this->getWebhookPayload();
        $event = data_get($payload, 'type');

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
        $secret = $this->getSettings('webhook_secret');
        $sigheader = (string) (request()->header('Stripe-Signature') ?? '');

        try {
            \Stripe\Webhook::constructEvent($this->getRawWebhookInput(), $sigheader, $secret);
        } catch (\Stripe\Exception\SignatureVerificationException | \UnexpectedValueException $e) {
            logger()->warning('Unable to validate Stripe webhook signature.', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }

        return true;
    }

    public function checkout($params)
    {
        $params = [
            ...$params,
            'line_items' => collect(data_get($params, 'line_items'))
                ->map(fn($item) => $this->normalizeLineItem($item))
                ->toArray(),
            'success_url' => route('__stripe.success', data_get($params, 'metadata', [])),
            'cancel_url' => route('__stripe.cancel', data_get($params, 'metadata', [])),
        ];

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
        $client = $this->getStripeClient();

        foreach ($client->webhookEndpoints->all()->autoPagingIterator() as $endpoint) {
            if (data_get($endpoint, 'url') === $url) {
                $client->webhookEndpoints->delete(data_get($endpoint, 'id'));
            }
        }

        $webhook = $client->webhookEndpoints->create([
            'url' => $url,
            'enabled_events' => [
                'checkout.session.async_payment_failed',
                'checkout.session.async_payment_succeeded',
                'checkout.session.completed',
                'checkout.session.expired',
                'invoice.paid',
                'invoice.payment_failed',
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

    protected function getRawWebhookInput() : string
    {
        return (string) request()->getContent();
    }

    protected function normalizeLineItem(array $item) : array
    {
        if (!isset($item['price_data']['unit_amount'])) {
            return $item;
        }

        $item['price_data']['unit_amount'] = $this->toStripeAmount($item['price_data']['unit_amount']);

        return $item;
    }

    protected function toStripeAmount($amount) : int
    {
        $clean = str_replace(',', '', (string) $amount);

        if (str_contains($clean, '.')) {
            return (int) round(((float) $clean) * 100);
        }

        return (int) $clean;
    }
}
