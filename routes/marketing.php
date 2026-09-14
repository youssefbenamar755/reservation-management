<?php

use App\Http\Controllers\MarketingController;
use App\Http\Controllers\Settings\MarketingSettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('/settings/marketing', [MarketingSettingsController::class, 'index'])->name('marketing-settings.index');
    Route::post('/settings/marketing/connect', [MarketingSettingsController::class, 'connect'])->middleware('throttle:6,1')->name('marketing-settings.connect');
    Route::delete('/settings/marketing/connection', [MarketingSettingsController::class, 'disconnect'])->name('marketing-settings.disconnect');
    Route::get('/marketing', [MarketingController::class, 'index'])->name('marketing.index');
    Route::get('/marketing/audience', [MarketingController::class, 'audience'])->name('marketing.audience');
    Route::post('/marketing/websites/{website}/discover', [MarketingController::class, 'discover'])->middleware('throttle:3,1')->name('marketing.discover');
    Route::post('/marketing/websites/{website}/contacts', [MarketingController::class, 'contact'])->middleware('throttle:30,1')->name('marketing.contacts');
    Route::post('/marketing/websites/{website}/import', [MarketingController::class, 'import'])->middleware('throttle:3,1')->name('marketing.import');
    Route::get('/marketing/templates', [MarketingController::class, 'templates'])->name('marketing.templates');
    Route::post('/marketing/templates', [MarketingController::class, 'template'])->name('marketing.templates.create');
    Route::put('/marketing/templates/{template}', [MarketingController::class, 'template'])->name('marketing.templates.update');
    Route::post('/marketing/preview', [MarketingController::class, 'preview'])->middleware('throttle:30,1')->name('marketing.preview');
    Route::post('/marketing/campaigns', [MarketingController::class, 'create'])->middleware('throttle:10,1')->name('marketing.campaigns.create');
    Route::get('/marketing/campaigns/{campaign}', [MarketingController::class, 'show'])->name('marketing.campaigns.show');
    Route::post('/marketing/campaigns/{campaign}/schedule', [MarketingController::class, 'schedule'])->middleware('throttle:6,1')->name('marketing.campaigns.schedule');
    Route::post('/marketing/campaigns/{campaign}/cancel', [MarketingController::class, 'cancel'])->middleware('throttle:10,1')->name('marketing.campaigns.cancel');
    Route::post('/marketing/campaigns/{campaign}/test', [MarketingController::class, 'test'])->middleware('throttle:3,1')->name('marketing.campaigns.test');
});
