<?php

return [
    /*
    |--------------------------------------------------------------------------
    | OpenAI API Key and Organization
    |--------------------------------------------------------------------------
    |
    | Here you may specify your OpenAI API Key and organization. This will be
    | used to authenticate with the OpenAI API - you can find your API key
    | on your OpenAI dashboard, at https://platform.openai.com/api-keys.
    |
    */

    'api_key' => env('OPENAI_API_KEY'),
    'organization' => env('OPENAI_ORGANIZATION'),

    /*
    |--------------------------------------------------------------------------
    | OpenAI Model Configuration
    |--------------------------------------------------------------------------
    */

    'model' => env('OPENAI_MODEL', 'gpt-5-nano'),
    'max_tokens' => env('OPENAI_MAX_TOKENS', 1500),
    'temperature' => env('OPENAI_TEMPERATURE', 1),
    'timeout' => env('OPENAI_TIMEOUT', 30),
    'max_retries' => env('OPENAI_MAX_RETRIES', 2),
];
