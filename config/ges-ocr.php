<?php

return [
    'ai' => [
        'provider' => env('GES_OCR_AI_PROVIDER', 'ollama'),
        'classification_confidence_threshold' => (float) env('GES_OCR_CLASSIFICATION_CONFIDENCE_THRESHOLD', env('OLLAMA_CLASSIFICATION_CONFIDENCE_THRESHOLD', 0.75)),
        'max_pages' => (int) env('GES_OCR_MAX_PAGES', env('OLLAMA_MAX_PAGES', 0)),
    ],
    'ollama' => [
        'base_url' => env('OLLAMA_BASE_URL', 'http://localhost:11434'),
        'text_model' => env('OLLAMA_TEXT_MODEL', 'qwen2.5:7b'),
        'vision_model' => env('OLLAMA_VISION_MODEL', 'qwen2.5vl:7b'),
        'connect_timeout' => (int) env('OLLAMA_CONNECT_TIMEOUT', 10),
        'timeout' => (int) env('OLLAMA_TIMEOUT', 120),
        'retry_times' => (int) env('OLLAMA_RETRY_TIMES', 2),
        'retry_sleep_ms' => (int) env('OLLAMA_RETRY_SLEEP_MS', 500),
        'basic_auth' => [
            'enabled' => filter_var(env('OLLAMA_BASIC_AUTH_ENABLED', false), FILTER_VALIDATE_BOOL),
            'username' => env('OLLAMA_BASIC_AUTH_USERNAME'),
            'password' => env('OLLAMA_BASIC_AUTH_PASSWORD'),
        ],
    ],
    'openai' => [
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'api_key' => env('OPENAI_API_KEY'),
        'text_model' => env('OPENAI_TEXT_MODEL', 'gpt-4.1-mini'),
        'vision_model' => env('OPENAI_VISION_MODEL', 'gpt-4.1-mini'),
        'connect_timeout' => (int) env('OPENAI_CONNECT_TIMEOUT', 10),
        'timeout' => (int) env('OPENAI_TIMEOUT', 120),
        'retry_times' => (int) env('OPENAI_RETRY_TIMES', 2),
        'retry_sleep_ms' => (int) env('OPENAI_RETRY_SLEEP_MS', 500),
    ],
    'mrz' => [
        'ocr_enabled' => filter_var(env('GES_OCR_MRZ_OCR_ENABLED', true), FILTER_VALIDATE_BOOL),
    ],
    'processing' => [
        'cleanup_temporary_files' => filter_var(env('GES_OCR_CLEANUP_TEMPORARY_FILES', true), FILTER_VALIDATE_BOOL),
    ],
];
