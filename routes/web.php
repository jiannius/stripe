<?php

use Illuminate\Support\Facades\Route;
use Jiannius\Stripe\Middleware\EnsureStripeIp;
use Jiannius\Stripe\Middleware\EnsureWebhookIdempotency;

Route::prefix('__stripe')->as('__stripe')->withoutMiddleware('web')->group(function () {
    Route::get('success', [\App\Http\Controllers\StripeController::class, 'success'])->name('.success');
    Route::get('cancel', [\App\Http\Controllers\StripeController::class, 'cancel'])->name('.cancel');

    $webhook = Route::post('webhook', [\App\Http\Controllers\StripeController::class, 'webhook'])->name('.webhook');

    $middleware = [];
    if (config('stripe.webhook.ip_allowlist')) $middleware[] = EnsureStripeIp::class;
    if (config('stripe.webhook.idempotent')) $middleware[] = EnsureWebhookIdempotency::class;
    if ($middleware) $webhook->middleware($middleware);
});
