<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI Engine Configuration
    |--------------------------------------------------------------------------
    |
    | Choose which AI engine to use: 'gemini' or 'openai'
    |
    */

    'engine' => env('AI_ENGINE', 'gemini'),

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
        'model' => env('GEMINI_MODEL', 'gemini-2.0-flash'),
        'max_tokens' => env('GEMINI_MAX_TOKENS', 500),
        'temperature' => env('GEMINI_TEMPERATURE', 0.7),
    ],

    /*
    |--------------------------------------------------------------------------
    | OpenAI Configuration (Legacy)
    |--------------------------------------------------------------------------
    |
    | Configuration for OpenAI API integration used by the chatbot AI engine.
    |
    */

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-4'),
        'max_tokens' => env('OPENAI_MAX_TOKENS', 500),
        'temperature' => env('OPENAI_TEMPERATURE', 0.7),
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
