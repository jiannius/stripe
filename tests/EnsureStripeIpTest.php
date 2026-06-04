<?php

namespace Jiannius\Stripe\Tests;

use Illuminate\Http\Request;
use Jiannius\Stripe\Middleware\EnsureStripeIp;

class EnsureStripeIpTest extends TestCase
{
    public function test_allows_listed_ip()
    {
        config(['stripe.webhook.ips' => ['1.2.3.4']]);
        $req = Request::create('/__stripe/webhook', 'POST', server: ['REMOTE_ADDR' => '1.2.3.4']);

        $response = (new EnsureStripeIp())->handle($req, fn() => response('OK'));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_rejects_unlisted_ip()
    {
        config(['stripe.webhook.ips' => ['1.2.3.4']]);
        $req = Request::create('/__stripe/webhook', 'POST', server: ['REMOTE_ADDR' => '9.9.9.9']);

        $response = (new EnsureStripeIp())->handle($req, fn() => response('OK'));

        $this->assertSame(403, $response->getStatusCode());
    }
}
