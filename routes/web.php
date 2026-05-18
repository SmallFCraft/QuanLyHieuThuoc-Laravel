<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProductCatalogController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Admin\InventoryController as AdminInventoryController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
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

// Profile routes
Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// Admin routes
Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard/revenue', [DashboardController::class, 'revenueChart'])->name('dashboard.revenue');
    Route::get('/dashboard/order-status', [DashboardController::class, 'orderStatusChart'])->name('dashboard.orderStatus');
    Route::get('/dashboard/top-products', [DashboardController::class, 'topProductsChart'])->name('dashboard.topProducts');
    
    // Products management
    Route::get('/products/data', [AdminProductController::class, 'data'])->name('products.data');
    Route::resource('products', AdminProductController::class);
    
    // Categories management
    Route::resource('categories', AdminCategoryController::class)->except(['show']);
    
    // Orders management
    Route::get('/orders', [AdminOrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/{MaDH}', [AdminOrderController::class, 'show'])->name('orders.show');
    Route::patch('/orders/{MaDH}/status', [AdminOrderController::class, 'updateStatus'])->name('orders.updateStatus');
    Route::post('/orders/{MaDH}/cancel', [AdminOrderController::class, 'cancel'])->name('orders.cancel');
    
    // Inventory management
    Route::get('/inventory', [AdminInventoryController::class, 'index'])->name('inventory.index');
    Route::get('/inventory/low-stock', [AdminInventoryController::class, 'lowStock'])->name('inventory.lowStock');
    Route::get('/inventory/expiring', [AdminInventoryController::class, 'expiring'])->name('inventory.expiring');
    Route::post('/inventory/adjust', [AdminInventoryController::class, 'adjust'])->name('inventory.adjust');
    
    // Users management
    Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
    Route::post('/users', [AdminUserController::class, 'store'])->name('users.store');
    Route::put('/users/{MaKH}', [AdminUserController::class, 'update'])->name('users.update');
    Route::patch('/users/{MaKH}/status', [AdminUserController::class, 'updateStatus'])->name('users.updateStatus');
});

require __DIR__.'/auth.php';
