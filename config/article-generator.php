<?php

return [
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'api_base' => env('OPENAI_API_BASE', 'https://api.openai.com/v1'),
        'default_model' => env('OPENAI_DEFAULT_MODEL', 'gpt-4o'),
        'timeout' => env('OPENAI_TIMEOUT', 900),
        'max_retries' => env('OPENAI_MAX_RETRIES', 3),
    ],

    'google' => [
        'api_key' => env('GOOGLE_GEMINI_API_KEY'),
        'default_model' => env('GOOGLE_DEFAULT_MODEL', 'gemini-2.0-flash-exp'),
        'timeout' => env('GOOGLE_TIMEOUT', 900),
        'max_retries' => env('GOOGLE_MAX_RETRIES', 3),
    ],

    'rate_limits' => [
        'openai_rpm' => env('OPENAI_RATE_LIMIT_RPM', 500),
        'google_rpm' => env('GOOGLE_RATE_LIMIT_RPM', 1000),
    ],

    'cache' => [
        'enabled' => env('CACHE_ENABLED', true),
        'ttl' => env('CACHE_TTL', 3600),
        'prefix' => 'article_gen',
    ],

    'queue' => [
        'connection' => env('QUEUE_CONNECTION', 'redis'),
        'name' => 'article-generation',
    ],

    'api_keys' => env('API_KEYS', ''),
];
