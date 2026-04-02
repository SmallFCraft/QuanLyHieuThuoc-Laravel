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

// Chatbot API routes - requires web authentication (session-based)
Route::middleware(['web', 'auth'])->prefix('chatbot')->group(function () {
    // Send message - 20 requests per minute
    Route::post('/message', [ChatbotController::class, 'sendMessage'])
        ->middleware('throttle:20,1');
    
    // Upload image - 30 requests per hour (more lenient for testing)
    Route::post('/upload-image', [ChatbotController::class, 'uploadImage'])
        ->middleware('throttle:30,60');
    
    // Cart operations - 30 requests per minute
    Route::get('/cart', [ChatbotController::class, 'getCart'])
        ->middleware('throttle:30,1');
    
    Route::post('/cart/add', [ChatbotController::class, 'addToCart'])
        ->middleware('throttle:30,1');
    
    Route::post('/cart/update', [ChatbotController::class, 'updateCartItem'])
        ->middleware('throttle:30,1');
    
    // Place order from chatbot - 10 requests per minute (allow retries for validation errors)
    Route::post('/place-order', [ChatbotController::class, 'placeOrder'])
        ->middleware('throttle:10,1');
    
    // Get chat history - no rate limit (read-only)
    Route::get('/history', [ChatbotController::class, 'getHistory']);
    
    // Clear chat history - no rate limit
    Route::delete('/history', [ChatbotController::class, 'clearHistory']);
});
