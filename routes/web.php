<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReminderController;
use App\Http\Controllers\ProductCatalogController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\FavoriteController;
use App\Http\Controllers\HealthCheckController;
use App\Http\Controllers\HealthProfileController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Admin\InventoryController as AdminInventoryController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Admin\ChatSessionController as AdminChatSessionController;
use App\Http\Controllers\Admin\TriageReportController as AdminTriageReportController;
use App\Http\Controllers\Pharmacist\TriageQueueController;
use Illuminate\Support\Facades\Route;

// Public routes
Route::get('/', [HomeController::class, 'index'])->name('home');

// Contact routes
Route::get('/contact', [ContactController::class, 'index'])->name('contact');
Route::post('/contact/submit', [ContactController::class, 'submit'])
    ->middleware('throttle:5,1')
    ->name('contact.submit');

// Product catalog routes
Route::get('/products', [ProductCatalogController::class, 'index'])->name('products.index');
Route::get('/products/{MaSP}', [ProductCatalogController::class, 'show'])->name('products.show');
Route::get('/products/category/{MaDM}', [ProductCatalogController::class, 'byCategory'])->name('products.category');
Route::get('/search', [ProductCatalogController::class, 'search'])->name('products.search');
Route::get('/api/search/suggestions', [ProductCatalogController::class, 'suggestions'])->name('products.suggestions');

// Shopping cart routes
Route::get('/cart', [CartController::class, 'index'])->name('cart.index');
Route::post('/cart/add', [CartController::class, 'add'])->name('cart.add');
Route::match(['post', 'put'], '/cart/update/{MaSP}', [CartController::class, 'update'])->name('cart.update');
Route::match(['post', 'delete'], '/cart/remove/{MaSP}', [CartController::class, 'remove'])->name('cart.remove');

// Checkout routes (requires authentication)
Route::middleware('auth')->group(function () {
    Route::get('/checkout', [CheckoutController::class, 'index'])->name('checkout.index');
    Route::post('/checkout/process', [CheckoutController::class, 'process'])->name('checkout.process');
    Route::get('/checkout/success/{MaDH}', [CheckoutController::class, 'success'])->name('checkout.success');
});

// Health triage routes (requires authentication)
Route::middleware('auth')->group(function () {
    Route::get('/health-check', [HealthCheckController::class, 'index'])->name('health.check');
    Route::post('/health-check/answer', [HealthCheckController::class, 'answer'])
        ->middleware('throttle:20,1')
        ->name('health.answer');
    Route::post('/health-check/back', [HealthCheckController::class, 'back'])->name('health.back');
    // One bounded AI follow-up round, offered on the wizard page after step 5.
    Route::post('/health-check/clarify', [HealthCheckController::class, 'clarify'])
        ->middleware('throttle:20,1')
        ->name('health.clarify');
    Route::post('/health-check/restart', [HealthCheckController::class, 'restart'])->name('health.restart');

    // Stop repeating the scope notice as a popup for the rest of the calendar day.
    // Decided server-side so the popup and its dismiss both work with JavaScript off.
    //
    // Own bucket, not a bare `throttle:10,1`: a numeric throttle keys on sha1(user id)
    // with no route component, so every numeric-throttled route in the app shares one
    // per-user counter. Measured — 40 hits on health.decision (its own limit is 120/min,
    // and the browser polls it every 1.5s while a worker decides) then 429 here, which
    // would mean the daily flag never gets written and the popup nags forever.
    Route::post('/health-check/disclaimer', [HealthCheckController::class, 'ackDisclaimer'])
        ->middleware('throttle:10,1,disclaimer-ack:')
        ->name('health.disclaimer.ack');
    // Progress of the queued outcome decision. Polled every second or two while the
    // patient waits, so it gets its own, higher limit.
    Route::get('/health-check/decision/{uuid}', [HealthCheckController::class, 'decision'])
        ->middleware('throttle:120,1')
        ->name('health.decision');
    Route::get('/health-check/history', [HealthCheckController::class, 'history'])->name('health.history');
    Route::get('/health-check/result/{MaSL}', [HealthCheckController::class, 'result'])
        ->whereNumber('MaSL')
        ->name('health.result');
    // Opt-in side channel: the AI/rule answer is the primary result, and the queue is
    // only entered when the customer asks for a human.
    Route::post('/health-check/result/{MaSL}/pharmacist', [HealthCheckController::class, 'requestPharmacist'])
        ->middleware('throttle:10,1')
        ->whereNumber('MaSL')
        ->name('health.requestPharmacist');

    // Follow-up from the email. The GET link only renders a confirmation page —
    // it is signed and changes nothing, so a mail scanner or prefetcher that opens
    // it cannot record an answer. The answer itself is a CSRF-protected POST.
    Route::get('/health-check/{MaSL}/follow-up', [ReminderController::class, 'followUpConfirm'])
        ->middleware('signed')
        ->whereNumber('MaSL')
        ->name('health.followup.confirm');
    Route::post('/health-check/{MaSL}/follow-up', [ReminderController::class, 'followUpResponse'])
        ->middleware('throttle:20,1')
        ->whereNumber('MaSL')
        ->name('health.followup');

    Route::get('/reminders', [ReminderController::class, 'index'])->name('reminders.index');
    Route::post('/reminders/{MaNN}/cancel', [ReminderController::class, 'cancel'])
        ->whereNumber('MaNN')
        ->name('reminders.cancel');
});

// Profile routes
Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::put('/profile/health', [HealthProfileController::class, 'update'])->name('profile.health.update');
    Route::delete('/profile/health', [HealthProfileController::class, 'destroy'])->name('profile.health.destroy');

    Route::get('/favorites/ids', [FavoriteController::class, 'ids'])->name('favorites.ids');
    Route::get('/favorites/section', [FavoriteController::class, 'section'])->name('favorites.section');
    Route::post('/favorites/sync', [FavoriteController::class, 'sync'])->name('favorites.sync');
    Route::post('/favorites/{product}/toggle', [FavoriteController::class, 'toggle'])->name('favorites.toggle');
    Route::delete('/favorites/{product}', [FavoriteController::class, 'destroy'])->name('favorites.destroy');
});

// Admin routes
// `audit` records every admin POST/PUT/PATCH/DELETE at the HTTP level. Model-level
// auditing (Traits\Auditable) only covers Product, ProductBatch, Order and
// ChatbotImage, so without this Category and User changes leave no trail.
Route::middleware(['auth', 'admin', 'audit'])->prefix('admin')->name('admin.')->group(function () {
    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard/revenue', [DashboardController::class, 'revenueChart'])->name('dashboard.revenue');
    Route::get('/dashboard/order-status', [DashboardController::class, 'orderStatusChart'])->name('dashboard.orderStatus');
    Route::get('/dashboard/top-products', [DashboardController::class, 'topProductsChart'])->name('dashboard.topProducts');
    
    // Products management
    Route::get('/products/data', [AdminProductController::class, 'data'])->name('products.data');
    Route::get('/products/export', [AdminProductController::class, 'export'])->name('products.export');
    Route::resource('products', AdminProductController::class);
    
    // Categories management
    Route::resource('categories', AdminCategoryController::class)->except(['show']);
    
    // Orders management
    Route::get('/orders', [AdminOrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/export', [AdminOrderController::class, 'export'])->name('orders.export');
    Route::get('/orders/{MaDH}', [AdminOrderController::class, 'show'])->name('orders.show');
    Route::patch('/orders/{MaDH}/status', [AdminOrderController::class, 'updateStatus'])->name('orders.updateStatus');
    Route::post('/orders/{MaDH}/cancel', [AdminOrderController::class, 'cancel'])->name('orders.cancel');
    
    // Inventory management
    Route::get('/inventory', [AdminInventoryController::class, 'index'])->name('inventory.index');
    Route::get('/inventory/export', [AdminInventoryController::class, 'export'])->name('inventory.export');
    Route::get('/inventory/low-stock', [AdminInventoryController::class, 'lowStock'])->name('inventory.lowStock');
    Route::get('/inventory/expiring', [AdminInventoryController::class, 'expiring'])->name('inventory.expiring');
    Route::post('/inventory/adjust', [AdminInventoryController::class, 'adjust'])->name('inventory.adjust');
    
    // Chat session lookup
    Route::get('/chat-sessions', [AdminChatSessionController::class, 'index'])->name('chatSessions.index');
    Route::get('/chat-sessions/{sessionId}/images/{image}', [AdminChatSessionController::class, 'image'])
        ->whereUuid('sessionId')
        ->whereNumber('image')
        ->name('chatSessions.images.show');
    Route::get('/chat-sessions/{sessionId}', [AdminChatSessionController::class, 'show'])
        ->whereUuid('sessionId')
        ->name('chatSessions.show');
    // Triage report — static segments must precede {MaSL} or they get captured by it
    Route::get('/triage-reports', [AdminTriageReportController::class, 'index'])->name('triageReports.index');
    Route::get('/triage-reports/export', [AdminTriageReportController::class, 'export'])->name('triageReports.export');
    Route::get('/triage-reports/chart/outcome', [AdminTriageReportController::class, 'outcomeChart'])->name('triageReports.outcomeChart');
    Route::get('/triage-reports/chart/trend', [AdminTriageReportController::class, 'trendChart'])->name('triageReports.trendChart');
    Route::get('/triage-reports/{MaSL}', [AdminTriageReportController::class, 'show'])
        ->whereNumber('MaSL')
        ->name('triageReports.show');

    // Users management
    Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
    Route::get('/users/export', [AdminUserController::class, 'export'])->name('users.export');
    Route::post('/users', [AdminUserController::class, 'store'])->name('users.store');
    Route::put('/users/{MaKH}', [AdminUserController::class, 'update'])->name('users.update');
    Route::patch('/users/{MaKH}/status', [AdminUserController::class, 'updateStatus'])->name('users.updateStatus');
});

/*
|--------------------------------------------------------------------------
| Pharmacist Routes (Triage Review Queue)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'pharmacist'])->prefix('pharmacist')->name('pharmacist.')->group(function () {
    Route::get('/triage-queue', [TriageQueueController::class, 'index'])->name('triage.index');
    Route::get('/triage-queue/{MaSL}', [TriageQueueController::class, 'show'])
        ->whereNumber('MaSL')
        ->name('triage.show');
    Route::post('/triage-queue/{MaSL}/claim', [TriageQueueController::class, 'claim'])
        ->whereNumber('MaSL')
        ->name('triage.claim');
    Route::post('/triage-queue/{MaSL}/reply', [TriageQueueController::class, 'reply'])
        ->whereNumber('MaSL')
        ->name('triage.reply');
    // POST, not GET: the draft costs a provider call, and GET show() writes an audit
    // row on every open. throttle so a held-down button cannot burn the AI budget.
    Route::post('/triage-queue/{MaSL}/draft', [TriageQueueController::class, 'draft'])
        ->middleware('throttle:10,1')
        ->whereNumber('MaSL')
        ->name('triage.draft');
    Route::post('/triage-queue/{MaSL}/escalate', [TriageQueueController::class, 'escalate'])
        ->whereNumber('MaSL')
        ->name('triage.escalate');
});

require __DIR__.'/auth.php';
