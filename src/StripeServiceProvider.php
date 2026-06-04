<?php

namespace Jiannius\Stripe;

use Illuminate\Support\ServiceProvider;

class StripeServiceProvider extends ServiceProvider
{
    /**
     * Register bindings into the container.
     *
     * @return void
     */
    public function register() : void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/stripe.php', 'stripe');
    }

    /**
     * Boot the package: load the package routes and bind the `stripe` singleton.
     *
     * @return void
     */
    public function boot() : void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->app->bind('stripe', fn($app) => new \Jiannius\Stripe\Stripe());

        $this->publishes([
            __DIR__.'/../config/stripe.php' => config_path('stripe.php'),
        ], 'stripe-config');
    }
}
