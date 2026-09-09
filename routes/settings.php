<?php

use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\TwoFactorAuthenticationController;
use App\Http\Controllers\Settings\UpdateController;
use App\Http\Controllers\Settings\EmailSettingsController;
use App\Http\Controllers\Settings\TrafficSettingsController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('auth')->group(function () {
    Route::get('settings/traffic', [TrafficSettingsController::class, 'index'])->name('traffic-settings.index');
    Route::put('settings/traffic/application', [TrafficSettingsController::class, 'application'])->middleware(['admin', 'throttle:6,1'])->name('traffic-settings.application');
    Route::post('settings/traffic/connect', [TrafficSettingsController::class, 'connect'])->middleware('throttle:6,1')->name('traffic-settings.connect');
    Route::get('settings/traffic/callback', [TrafficSettingsController::class, 'callback'])->name('traffic-settings.callback');
    Route::delete('settings/traffic/connection', [TrafficSettingsController::class, 'disconnect'])->name('traffic-settings.disconnect');
    Route::post('settings/traffic/catalog', [TrafficSettingsController::class, 'catalog'])->middleware('throttle:6,1')->name('traffic-settings.catalog');
    Route::put('settings/traffic/websites/{website}', [TrafficSettingsController::class, 'website'])->middleware('throttle:20,1')->name('traffic-settings.website');
    Route::get('settings/email', [EmailSettingsController::class, 'index'])->name('email-settings.index');
    Route::put('settings/email/application', [EmailSettingsController::class, 'application'])->middleware(['admin', 'throttle:6,1'])->name('email-settings.application');
    Route::post('settings/email/connect', [EmailSettingsController::class, 'connect'])->middleware('throttle:6,1')->name('email-settings.connect');
    Route::get('settings/email/callback', [EmailSettingsController::class, 'callback'])->name('email-settings.callback');
    Route::delete('settings/email/connection', [EmailSettingsController::class, 'disconnect'])->name('email-settings.disconnect');
    Route::post('settings/email/aliases', [EmailSettingsController::class, 'refreshAliases'])->middleware('throttle:6,1')->name('email-settings.aliases');
    Route::put('settings/email/websites/{website}', [EmailSettingsController::class, 'website'])->middleware('throttle:20,1')->name('email-settings.website');
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('user-password.edit');

    Route::put('settings/password', [PasswordController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::get('settings/appearance', function () {
        return Inertia::render('settings/Appearance');
    })->name('appearance.edit');

    Route::get('settings/two-factor', [TwoFactorAuthenticationController::class, 'show'])
        ->name('two-factor.show');

    // Admin Only Routes
    Route::middleware('admin')->group(function () {
        // User Management
        Route::get('settings/users', [\App\Http\Controllers\Settings\UserManagementController::class, 'index'])
            ->name('users.index');
        Route::post('settings/users', [\App\Http\Controllers\Settings\UserManagementController::class, 'store'])
            ->name('users.store');
        Route::put('settings/users/{user}', [\App\Http\Controllers\Settings\UserManagementController::class, 'update'])
            ->name('users.update');
        Route::delete('settings/users/{user}', [\App\Http\Controllers\Settings\UserManagementController::class, 'destroy'])
            ->name('users.destroy');

        // System Updates
        Route::get('settings/updates', [UpdateController::class, 'index'])
            ->name('updates.index');
        Route::post('settings/updates/run', [UpdateController::class, 'run'])
            ->name('updates.run');
    });
});
