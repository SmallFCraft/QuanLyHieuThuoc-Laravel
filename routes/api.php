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

// Progress of any queued AI job (chat turn, triage advice rewrite). Outside the
// chatbot prefix because triage uses it too. Cheap on purpose (one indexed row) and
// polled every second or two, so it gets its own, higher limit.
Route::middleware(['web', 'throttle:300,1'])
    ->get('/ai-jobs/{uuid}', [ChatbotController::class, 'pollJob'])
    ->name('ai-jobs.poll');

// Same scope as the poll route: the browser fires it when the customer presses stop,
// so a late worker never posts an answer to a question that was withdrawn.
Route::middleware(['web', 'throttle:60,1'])
    ->delete('/ai-jobs/{uuid}', [ChatbotController::class, 'cancelJob'])
    ->name('ai-jobs.cancel');

// Chatbot API routes - Mixed authentication (some require auth, some allow guest)
Route::middleware(['web'])->prefix('chatbot')->group(function () {
    // Guest-accessible routes (require login to actually use)
    Route::post('/message', [ChatbotController::class, 'sendMessage'])
        ->middleware('throttle:60,1');
    
    Route::post('/upload-image', [ChatbotController::class, 'uploadImage'])
        ->middleware('throttle:60,60');
    
    // Authenticated-only routes
    Route::middleware('auth')->group(function () {
        // Chat history re-renders past uploads through this route; the live turn uses a
        // blob: URL that does not survive a reload. Owner-scoped in the controller.
        Route::get('/images/{image}', [ChatbotController::class, 'image'])
            ->whereNumber('image')
            ->middleware('throttle:120,1')
            ->name('chatbot.images.show');

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
