<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Chatbot AI Provider Configuration
    |--------------------------------------------------------------------------
    |
    | Generic /v1-compatible AI provider used by the text chatbot.
    | Supported modes: chat_completions, responses, anthropic_messages.
    |
    | The fallback block is optional. When base_url, api_key and model are all
    | present, the chatbot retries the request on that provider whenever the
    | primary one fails (auth, upstream, rate limit, timeout).
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

        // Answer the question typed with an uploaded image (grounded on what the
        // vision step read plus the catalog lookup).
        'image_answer' => env('AI_IMAGE_ANSWER', true),

        // Required by the Anthropic messages API, ignored by the other modes.
        'max_tokens' => env('AI_MAX_TOKENS', 2048),
        'anthropic_version' => env('AI_ANTHROPIC_VERSION', '2023-06-01'),
        'thinking' => env('AI_THINKING', 'disabled'),

        'fallback' => [
            'mode' => env('AI_FALLBACK_API_MODE', 'anthropic_messages'),
            'base_url' => env('AI_FALLBACK_BASE_URL'),
            'api_key' => env('AI_FALLBACK_API_KEY'),
            'model' => env('AI_FALLBACK_MODEL'),
            'timeout' => env('AI_FALLBACK_TIMEOUT', env('AI_TIMEOUT', 20)),
        ],
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
