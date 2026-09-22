<?php

use App\Http\Controllers\DocumentationController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('/documentation', [DocumentationController::class, 'index'])->name('documentation.index');
    Route::get('/documentation/download', [DocumentationController::class, 'download'])->name('documentation.download');
});
