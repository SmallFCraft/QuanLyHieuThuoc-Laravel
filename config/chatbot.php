<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Google Gemini Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Google Gemini API integration used by the chatbot AI engine.
    |
    */

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        // Comma/semicolon/pipe/newline separated fallback keys, e.g.:
        // GEMINI_API_KEYS="key_1,key_2,key_3"
        'api_keys' => env('GEMINI_API_KEYS', ''),
        'model' => env('GEMINI_MODEL', 'gemini-2.0-flash'),
        'max_tokens' => env('GEMINI_MAX_TOKENS', 500),
        'temperature' => env('GEMINI_TEMPERATURE', 0.7),
        'include_thoughts' => env('GEMINI_INCLUDE_THOUGHTS', false),
        'thinking_budget' => env('GEMINI_THINKING_BUDGET', 0),
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
