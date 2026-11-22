<?php

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CompanyLogoController;
use App\Http\Controllers\CompanyRegistrationController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\FacilityController;
use App\Http\Controllers\FacilityUserController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\MessageLogController;
use App\Http\Controllers\PayController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PaystackWebhookController;
use App\Http\Controllers\PlatformController;
use App\Http\Controllers\PortalController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\ReceivablesController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SmsDeliveryReportController;
use App\Http\Controllers\SmsLogController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// PayXbills website
Route::get('/', fn () => Inertia::render('Public/Home'))->name('home');
Route::get('/features', fn () => Inertia::render('Public/Services'))->name('services');
Route::redirect('/services', '/features', 301);
Route::get('/about', fn () => Inertia::render('Public/About'))->name('about');
Route::get('/contact', [ContactController::class, 'create'])->name('contact');
Route::post('/contact', [ContactController::class, 'store'])->middleware('throttle:5,1')->name('contact.store');

// A company signs up and becomes a PayXbills client with its own admin login
Route::middleware('guest')->group(function () {
    Route::get('/register', [CompanyRegistrationController::class, 'create'])->name('register');
    Route::post('/register', [CompanyRegistrationController::class, 'store'])->middleware('throttle:5,1')->name('register.store');
});

// Pay link sent to facility users by email and SMS (signed, no login needed)
Route::get('/pay/callback', [PayController::class, 'callback'])->name('pay.callback');
Route::get('/pay/{occupancy}', [PayController::class, 'show'])->middleware('signed')->name('pay.show');
Route::post('/pay/{occupancy}', [PayController::class, 'start'])->middleware(['signed', 'throttle:10,1'])->name('pay.start');

// Receipt link sent after payment (signed, no login needed)
Route::get('/r/{payment}', [ReceiptController::class, 'public'])->middleware('signed')->name('receipts.public');

// Company logos, shown on receipts and pay pages that open without logging in
Route::get('/logos/{company}', [CompanyLogoController::class, 'show'])->name('companies.logo');

// Provider webhooks (CSRF-exempt in bootstrap/app.php)
Route::post('/webhooks/paystack', PaystackWebhookController::class)->middleware('throttle:120,1')->name('webhooks.paystack');
Route::post('/webhooks/africastalking/sms-delivery', SmsDeliveryReportController::class)->middleware('throttle:120,1')->name('webhooks.sms-delivery');

Route::middleware(['auth', 'usable'])->group(function () {
    // Staff go to the dashboard, facility users to their portal, platform admins to the platform area
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    // My account stays reachable while a new password is being chosen (see EnsureAccountIsUsable)
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');

    // Return to the admin account after impersonating (open to the impersonated user)
    Route::post('/impersonate/leave', [UserController::class, 'leaveImpersonation'])->name('impersonate.leave');

    // Fivestar Technologies: every company on PayXbills
    Route::middleware('can:manage-companies')->prefix('platform')->name('platform.')->group(function () {
        Route::get('/companies', [PlatformController::class, 'index'])->name('companies');
        Route::put('/companies/{company}', [PlatformController::class, 'update'])->name('companies.update');
        Route::post('/companies/{company}/impersonate', [PlatformController::class, 'impersonate'])->name('companies.impersonate');
    });
});

// Everything below works inside the logged-in user's company
Route::middleware(['auth', 'usable', 'company'])->group(function () {
    // Facility user portal
    Route::middleware('can:use-portal')->group(function () {
        Route::get('/portal', [PortalController::class, 'index'])->name('portal');
        Route::post('/portal/pay', [PortalController::class, 'pay'])->middleware('throttle:10,1')->name('portal.pay');
        Route::post('/portal/payments', [PortalController::class, 'submitPayment'])->name('portal.payments.store');
    });

    // Receipts and payment proof: staff who can see the payment, or the facility user who made it
    Route::get('/receipts/{payment}', [ReceiptController::class, 'show'])->name('receipts.show');
    Route::get('/payment-attachments/{attachment}', [PaymentController::class, 'attachment'])->name('payments.attachment');

    Route::get('/facilities', [FacilityController::class, 'index'])->can('view-facilities')->name('facilities');
    Route::get('/facilities/{facility}', [FacilityController::class, 'show'])->can('view-facilities')->name('facilities.show');
    Route::middleware('can:manage-facilities')->group(function () {
        Route::post('/facilities', [FacilityController::class, 'store'])->name('facilities.store');
        Route::put('/facilities/{facility}', [FacilityController::class, 'update'])->name('facilities.update');
        Route::post('/facilities/{facility}/spaces', [FacilityController::class, 'storeSpaces'])->name('spaces.store');
        Route::put('/spaces/{space}', [FacilityController::class, 'updateSpace'])->name('spaces.update');
    });

    Route::middleware('can:manage-categories')->group(function () {
        Route::get('/categories', [CategoryController::class, 'index'])->name('categories');
        Route::post('/categories', [CategoryController::class, 'store'])->name('categories.store');
        Route::put('/categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
    });

    Route::get('/facility-users', [FacilityUserController::class, 'index'])->can('view-facility-users')->name('facility-users');
    Route::post('/facility-users', [FacilityUserController::class, 'store'])->can('register-facility-users')->name('facility-users.store');
    Route::put('/facility-users/{occupancy}', [FacilityUserController::class, 'update'])->can('register-facility-users')->name('facility-users.update');
    Route::post('/facility-users/{occupancy}/resend-login', [FacilityUserController::class, 'resendLogin'])->can('register-facility-users')->name('facility-users.resend-login');
    Route::post('/facility-users/{occupancy}/remind', [FacilityUserController::class, 'remind'])->can('send-reminders')->name('facility-users.remind');
    Route::post('/facility-users/{occupancy}/removal', [FacilityUserController::class, 'requestRemoval'])->can('request-removals')->name('facility-users.removal');
    Route::post('/removals/{removal}/approve', [FacilityUserController::class, 'approveRemoval'])->can('approve-removals')->name('removals.approve');
    Route::post('/removals/{removal}/reject', [FacilityUserController::class, 'rejectRemoval'])->can('approve-removals')->name('removals.reject');

    Route::get('/invoices', [InvoiceController::class, 'index'])->can('view-invoices')->name('invoices');
    Route::post('/invoices/generate', [InvoiceController::class, 'generate'])->can('manage-invoices')->name('invoices.generate');
    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->can('view-invoices')->name('invoices.show');
    Route::post('/invoices/{invoice}/send', [InvoiceController::class, 'send'])->can('manage-invoices')->name('invoices.send');
    Route::post('/invoices/{invoice}/cancel', [InvoiceController::class, 'cancel'])->can('manage-invoices')->name('invoices.cancel');

    Route::get('/payments', [PaymentController::class, 'index'])->can('view-payments')->name('payments');
    Route::post('/payments', [PaymentController::class, 'store'])->can('record-payments')->name('payments.store');
    Route::post('/payments/{payment}/attachments', [PaymentController::class, 'attach'])->can('record-payments')->name('payments.attach');
    Route::post('/payments/{payment}/clear', [PaymentController::class, 'clear'])->can('clear-payments')->name('payments.clear');
    Route::post('/payments/{payment}/reject', [PaymentController::class, 'reject'])->can('clear-payments')->name('payments.reject');

    Route::get('/receivables', ReceivablesController::class)->can('view-receivables')->name('receivables');

    Route::get('/expenditure', [ExpenseController::class, 'index'])->can('view-expenditure')->name('expenditure');
    Route::post('/expenditure', [ExpenseController::class, 'store'])->can('create-memos')->name('expenditure.store');
    Route::post('/expenditure/{expense}/approve', [ExpenseController::class, 'approve'])->can('approve-memos')->name('expenditure.approve');
    Route::post('/expenditure/{expense}/reject', [ExpenseController::class, 'reject'])->can('approve-memos')->name('expenditure.reject');

    Route::get('/reports', [ReportController::class, 'index'])->can('view-reports')->name('reports');
    Route::get('/reports/{report}', [ReportController::class, 'download'])->can('view-reports')->name('reports.download');

    Route::get('/analytics', AnalyticsController::class)->can('view-analytics')->name('analytics');

    Route::get('/notifications', [MessageLogController::class, 'index'])->can('view-notifications')->name('notifications');
    Route::post('/notifications/{message}/retry', [MessageLogController::class, 'retry'])->can('send-reminders')->name('notifications.retry');

    Route::middleware('can:view-sms-logs')->group(function () {
        Route::get('/sms-logs', [SmsLogController::class, 'index'])->name('sms-logs');
        Route::post('/sms-logs/{smsLog}/retry', [SmsLogController::class, 'retry'])->name('sms-logs.retry');
    });

    Route::middleware('can:manage-settings')->group(function () {
        Route::get('/settings', [SettingsController::class, 'edit'])->name('settings');
        Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
        Route::post('/settings/logo', [CompanyLogoController::class, 'store'])->name('settings.logo');
        Route::delete('/settings/logo', [CompanyLogoController::class, 'destroy'])->name('settings.logo.destroy');
    });

    Route::middleware('can:manage-users')->group(function () {
        Route::get('/users', [UserController::class, 'index'])->name('users');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::post('/users/{user}/impersonate', [UserController::class, 'impersonate'])->name('users.impersonate');
    });
});

require __DIR__.'/auth.php';
