<?php

use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\TwoFactorAuthenticationController;
use App\Http\Controllers\Settings\UpdateController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('auth')->group(function () {
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
