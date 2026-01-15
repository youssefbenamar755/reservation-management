<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FfSubmissionController;
use App\Http\Controllers\WebsiteController;
use App\Http\Controllers\WcOrderController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\CustomersController;
use App\Http\Controllers\NotificationController;

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

Route::get('dashboard', DashboardController::class)
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

require __DIR__.'/settings.php';


Route::middleware(['auth'])->group(function () {
    Route::post('websites/sync-all-woocommerce-orders', [WebsiteController::class, 'syncAllWooCommerceOrders'])
        ->name('websites.sync-all-woocommerce-orders');
    Route::resource('websites', WebsiteController::class);
    Route::post('websites/{website}/test-woocommerce', [WebsiteController::class, 'testWooCommerce'])
        ->name('websites.test-woocommerce');
    Route::post('websites/{website}/test-fluent-forms', [WebsiteController::class, 'testFluentForms'])
        ->name('websites.test-fluent-forms');
    Route::post('websites/{website}/sync-woocommerce-orders', [WebsiteController::class, 'syncWooCommerceOrders'])
        ->name('websites.sync-woocommerce-orders');
    Route::post('websites/{website}/sync-fluent-form', [WebsiteController::class, 'syncFluentForm'])
        ->name('websites.sync-fluent-form');
});
Route::middleware(['auth'])->group(function () {
    Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics.index');
    Route::get('/customers', [CustomersController::class, 'index'])->name('customers.index');
    Route::get('/customers/{email}', [CustomersController::class, 'show'])->name('customers.show');
    Route::get('/orders', [WcOrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/{order}', [WcOrderController::class, 'show'])->name('orders.show');
    Route::put('/orders/{order}', [WcOrderController::class, 'update'])->name('orders.update');
    Route::post('/orders/{order}/generate-amadeus-code', [WcOrderController::class, 'generateAmadeusCode'])->name('orders.generate-amadeus-code');
    
    // Submissions - Forms level
    Route::get('/submissions', [FfSubmissionController::class, 'index'])->name('submissions.index');
    Route::get('/websites/{website}/forms', [FfSubmissionController::class, 'getFormsForWebsite'])->name('websites.forms');
    // Form entries list
    Route::get('/submissions/forms/{website}/{form_id}', [FfSubmissionController::class, 'formEntries'])->name('submissions.form-entries');
    // Entry details
    Route::get('/submissions/entries/{entry}', [FfSubmissionController::class, 'entryDetails'])->name('submissions.entry-details');
    Route::post('/submissions/entries/{entry}/generate-amadeus-code', [FfSubmissionController::class, 'generateAmadeusCode'])->name('submissions.generate-amadeus-code');
    Route::post('/submissions/entries/{submission}/generate-pnr', [FfSubmissionController::class, 'generatePnr'])->name('submissions.generate-pnr');
    Route::get('/submissions/entries/{entry}/download-pdf', [FfSubmissionController::class, 'downloadPnrPdf'])->name('submissions.download-pdf');
    Route::delete('/submissions/entries/{entry}', [FfSubmissionController::class, 'destroy'])->name('submissions.destroy');
    
    // Form schema sync routes
    Route::post('/submissions/forms/{website}/{form_id}/sync-schema', [FfSubmissionController::class, 'syncFormSchema'])->name('submissions.sync-form-schema');
    Route::post('/submissions/forms/{website}/sync-all-schemas', [FfSubmissionController::class, 'syncAllFormSchemas'])->name('submissions.sync-all-schemas');
    
    // Delete all submissions for a form
    Route::delete('/submissions/forms/{website}/{form_id}', [FfSubmissionController::class, 'destroyAll'])->name('submissions.destroy-all');
    
    // Notifications
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markAsRead'])->name('notifications.mark-as-read');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead'])->name('notifications.mark-all-as-read');
});