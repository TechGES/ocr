<?php

return [
    'ollama' => [
        'base_url' => env('OLLAMA_BASE_URL', 'http://localhost:11434'),
        'text_model' => env('OLLAMA_TEXT_MODEL', 'qwen2.5:7b'),
        'vision_model' => env('OLLAMA_VISION_MODEL', 'qwen2.5vl:7b'),
        'connect_timeout' => (int) env('OLLAMA_CONNECT_TIMEOUT', 10),
        'timeout' => (int) env('OLLAMA_TIMEOUT', 120),
        'retry_times' => (int) env('OLLAMA_RETRY_TIMES', 2),
        'retry_sleep_ms' => (int) env('OLLAMA_RETRY_SLEEP_MS', 500),
        'classification_confidence_threshold' => (float) env('OLLAMA_CLASSIFICATION_CONFIDENCE_THRESHOLD', 0.75),
        'max_pages' => (int) env('OLLAMA_MAX_PAGES', 0),
        'basic_auth' => [
            'enabled' => filter_var(env('OLLAMA_BASIC_AUTH_ENABLED', false), FILTER_VALIDATE_BOOL),
            'username' => env('OLLAMA_BASIC_AUTH_USERNAME'),
            'password' => env('OLLAMA_BASIC_AUTH_PASSWORD'),
        ],
    ],
    'mrz' => [
        'ocr_enabled' => filter_var(env('GES_OCR_MRZ_OCR_ENABLED', true), FILTER_VALIDATE_BOOL),
    ],
    'processing' => [
        'cleanup_temporary_files' => filter_var(env('GES_OCR_CLEANUP_TEMPORARY_FILES', true), FILTER_VALIDATE_BOOL),
    ],
];
