<?php

namespace Jiannius\Stripe;

use Illuminate\Support\ServiceProvider;

class StripeServiceProvider extends ServiceProvider
{
    // register
    public function register() : void
    {
        //
    }

    // boot
    public function boot() : void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->app->bind('stripe', fn($app) => new \Jiannius\Stripe\Stripe());
    }
}