<?php

use App\Http\Controllers\TroLyChatbotController;
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

// Progress of any queued AI job (chat turn, triage advice rewrite). Outside the
// chatbot prefix because triage uses it too. Cheap on purpose (one indexed row) and
// polled every second or two, so it gets its own, higher limit.
Route::middleware(['web', 'throttle:300,1'])
    ->get('/ai-jobs/{uuid}', [TroLyChatbotController::class, 'pollJob'])
    ->name('cong-viec-ai.kiem-tra');

// Same scope as the poll route: the browser fires it when the customer presses stop,
// so a late worker never posts an answer to a question that was withdrawn.
Route::middleware(['web', 'throttle:60,1'])
    ->delete('/ai-jobs/{uuid}', [TroLyChatbotController::class, 'cancelJob'])
    ->name('cong-viec-ai.huy');

// Chatbot API routes - Mixed authentication (some require auth, some allow guest)
Route::middleware(['web'])->prefix('chatbot')->group(function () {
    // Guest-accessible routes (require login to actually use)
    Route::post('/message', [TroLyChatbotController::class, 'sendMessage'])
        ->middleware('throttle:60,1');
    
    Route::post('/upload-image', [TroLyChatbotController::class, 'uploadImage'])
        ->middleware('throttle:60,60');
    
    // Authenticated-only routes
    Route::middleware('auth')->group(function () {
        // Chat history re-renders past uploads through this route; the live turn uses a
        // blob: URL that does not survive a reload. Owner-scoped in the controller.
        Route::get('/images/{image}', [TroLyChatbotController::class, 'image'])
            ->whereNumber('image')
            ->middleware('throttle:120,1')
            ->name('chatbot.anh.show');

        // Cart operations
        Route::get('/cart', [TroLyChatbotController::class, 'getCart'])
            ->middleware('throttle:60,1');
        
        Route::post('/cart/add', [TroLyChatbotController::class, 'addToCart'])
            ->middleware('throttle:60,1');
        
        Route::post('/cart/update', [TroLyChatbotController::class, 'updateCartItem'])
            ->middleware('throttle:60,1');
        
        // Place order from chatbot
        Route::post('/place-order', [TroLyChatbotController::class, 'placeOrder'])
            ->middleware('throttle:60,1');
        
        // Chat history
        Route::get('/history', [TroLyChatbotController::class, 'getHistory']);
        Route::delete('/history', [TroLyChatbotController::class, 'clearHistory']);
    });
});
