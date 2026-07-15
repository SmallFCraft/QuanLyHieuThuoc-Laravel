<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Chatbot AI Provider Configuration
    |--------------------------------------------------------------------------
    |
    | Generic /v1-compatible AI provider used by the text chatbot.
    | Supported modes: chat_completions, responses.
    |
    */

    'ai' => [
        'mode' => env('AI_API_MODE', 'chat_completions'),
        'base_url' => env('AI_BASE_URL'),
        'api_key' => env('AI_API_KEY'),
        'model' => env('AI_MODEL'),
        'timeout' => env('AI_TIMEOUT', 20),
        'connect_timeout' => env('AI_CONNECT_TIMEOUT', 5),
        'grounded_response' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | OCR Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Tesseract OCR service used for prescription and
    | receipt image recognition.
    |
    */

    'ocr' => [
        'tesseract_path' => env('TESSERACT_PATH', '/usr/bin/tesseract'),
        'languages' => ['vie', 'eng'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Session Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for chat session management and history storage.
    |
    */

    'session' => [
        'max_messages' => 50,
        'encryption' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Image Upload Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for prescription and receipt image uploads.
    |
    */

    'images' => [
        'max_size' => 5120, // KB
        'allowed_types' => ['jpeg', 'jpg', 'png'],
        'storage_path' => 'chatbot-uploads',
        'cleanup_after' => 24, // hours
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting Configuration
    |--------------------------------------------------------------------------
    |
    | Rate limits for chatbot operations to prevent abuse.
    |
    */

    'rate_limits' => [
        'messages_per_minute' => 20,
        'images_per_hour' => 10,
    ],

];
