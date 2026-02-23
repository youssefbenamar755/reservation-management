<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Webhook\WooWebhookController;
use App\Http\Controllers\Webhook\FluentWebhookController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('v1/webhooks')
    ->middleware(['throttle:webhook'])
    ->group(function () {
        Route::post('woocommerce/{website:slug}', WooWebhookController::class);
        Route::post('fluentforms/{website:slug}', FluentWebhookController::class);
    });