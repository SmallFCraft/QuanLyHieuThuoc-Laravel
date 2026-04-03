<?php

use App\Http\Controllers\ChatbotController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Chatbot API routes - Mixed authentication (some require auth, some allow guest)
Route::middleware(['web'])->prefix('chatbot')->group(function () {
    // Guest-accessible routes (require login to actually use)
    Route::post('/message', [ChatbotController::class, 'sendMessage'])
        ->middleware('throttle:60,1');
    
    Route::post('/upload-image', [ChatbotController::class, 'uploadImage'])
        ->middleware('throttle:60,60');
    
    // Authenticated-only routes
    Route::middleware('auth')->group(function () {
        // Cart operations
        Route::get('/cart', [ChatbotController::class, 'getCart'])
            ->middleware('throttle:60,1');
        
        Route::post('/cart/add', [ChatbotController::class, 'addToCart'])
            ->middleware('throttle:60,1');
        
        Route::post('/cart/update', [ChatbotController::class, 'updateCartItem'])
            ->middleware('throttle:60,1');
        
        // Place order from chatbot
        Route::post('/place-order', [ChatbotController::class, 'placeOrder'])
            ->middleware('throttle:60,1');
        
        // Chat history
        Route::get('/history', [ChatbotController::class, 'getHistory']);
        Route::delete('/history', [ChatbotController::class, 'clearHistory']);
    });
});
