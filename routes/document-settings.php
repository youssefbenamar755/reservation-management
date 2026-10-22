<?php

use App\Http\Controllers\Settings\DocumentSettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('settings/email-documents')->name('document-settings.')->group(function () {
    Route::get('/', [DocumentSettingsController::class, 'index'])->name('index');
    Route::put('/{website}', [DocumentSettingsController::class, 'update'])->middleware('throttle:20,1,document-settings')->name('update');
    Route::post('/{website}/disable', [DocumentSettingsController::class, 'disable'])->middleware('throttle:20,1,document-disable')->name('disable');
    Route::post('/{website}/preview', [DocumentSettingsController::class, 'preview'])->middleware('throttle:30,1,document-sample')->name('preview');
    Route::post('/{website}/restore', [DocumentSettingsController::class, 'restore'])->name('restore');
    Route::post('/{website}/capability', [DocumentSettingsController::class, 'capability'])->middleware('throttle:6,1,document-capability')->name('capability');
    Route::post('/{website}/sync-brand', [DocumentSettingsController::class, 'syncBrand'])->middleware('throttle:6,1,document-brand-sync')->name('sync-brand');
});
