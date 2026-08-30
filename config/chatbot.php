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

        // Health triage ("Sàng lọc"). On by default: AI is the primary layer of the
        // feature. The rule engine still decides every outcome, and each seam
        // degrades to the deterministic result when the provider is unavailable, so
        // "on" is safe. Env vars exist only as a kill switch.
        // `timeout` is deliberately far below AI_TIMEOUT — a triage call sits in a
        // synchronous form POST, so it must fail fast rather than stall the wizard.
        'triage' => [
            'fact_extraction' => env('AI_TRIAGE_FACTS', true),
            'clarify_round' => env('AI_TRIAGE_CLARIFY', true),
            'advice_rewrite' => env('AI_TRIAGE_ADVICE', true),
            'pharmacist_draft' => env('AI_TRIAGE_DRAFT', true),
            // Detailed result-page briefing. Runs in a worker after the outcome is
            // final, so it gets a real budget instead of the wizard's fail-fast one:
            // it asks for six sections plus a product pick in one call.
            'briefing' => env('AI_TRIAGE_BRIEFING', true),
            'briefing_timeout' => env('AI_TRIAGE_BRIEFING_TIMEOUT', 90),
            // Fact extraction and the pharmacist draft may run inside the wizard's form
            // POST, so they keep the fail-fast budget.
            'timeout' => env('AI_TRIAGE_TIMEOUT', 30),
            // The advice rewrite only ever runs from ProcessTriageAdviceRewrite, so it
            // does not need to fail fast. Measured 2026-08-28: the same provider answered
            // in 2s, 7s, 33s and 62s on comparable prompts, and a budget sized for the
            // fast case discards the answer in the slow one.
            'advice_timeout' => env('AI_TRIAGE_ADVICE_TIMEOUT', 45),
        ],

        // A ceiling on ANSWER length, not the context window — the two are unrelated,
        // and a "generous" value here is simply rejected as out of range. Required by
        // the Anthropic messages API, ignored by the other modes.
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
    | Image Upload Configuration
    |--------------------------------------------------------------------------
    |
    | Prescription and receipt uploads. Read by ChatbotController (validation),
    | ImageRecognitionService (storage) and chatbot:cleanup-images (retention).
    |
    | Not configured here: rate limits, which are route middleware — see the
    | `throttle:` calls in routes/api.php. Chat history is capped by the
    | chat:cleanup command, not by a session setting.
    |
    */

    'images' => [
        'max_size' => 5120, // KB
        'allowed_types' => ['jpeg', 'jpg', 'png'],
        'storage_path' => 'chatbot-uploads',
        'cleanup_after' => 24, // hours
    ],

];
