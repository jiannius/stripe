<?php

use Illuminate\Support\Facades\Route;

Route::prefix('__stripe')->as('__stripe')->withoutMiddleware('web')->group(function () {
    Route::get('success', [\App\Http\Controllers\StripeController::class, 'success'])->name('.success');
    Route::get('cancel', [\App\Http\Controllers\StripeController::class, 'cancel'])->name('.cancel');
    Route::post('webhook', [\App\Http\Controllers\StripeController::class, 'webhook'])->name('.webhook');
});
