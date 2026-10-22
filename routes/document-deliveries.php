<?php

use App\Http\Controllers\DocumentDeliveryController;
use Illuminate\Support\Facades\Route;

Route::post('settings/email-documents/{website}/test', [\App\Http\Controllers\DocumentTestController::class, 'send'])->middleware(['auth', 'verified', 'throttle:3,1,document-test']);

Route::middleware(['auth', 'verified'])->prefix('orders/{order}/document-deliveries')->group(function () {
    Route::post('documents', [DocumentDeliveryController::class, 'documents'])->middleware('throttle:10,1,document-upload');
    Route::get('documents/{document}', [DocumentDeliveryController::class, 'download']);
    Route::post('prepare', [DocumentDeliveryController::class, 'prepare'])->middleware('throttle:10,1,document-prepare');
    Route::get('{delivery}', [DocumentDeliveryController::class, 'show']);
    Route::post('{delivery}/send', [DocumentDeliveryController::class, 'send'])->middleware('throttle:6,1,document-send');
    Route::post('{delivery}/sync', [DocumentDeliveryController::class, 'sync'])->middleware('throttle:6,1,document-sync');
    Route::post('{delivery}/review', [DocumentDeliveryController::class, 'review'])->middleware('throttle:6,1,document-review');
});
