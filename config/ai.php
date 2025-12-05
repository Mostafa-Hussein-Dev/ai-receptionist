<?php


return [

    /*
    |--------------------------------------------------------------------------
    | AI Service Provider
    |--------------------------------------------------------------------------
    |
    | Choose which AI provider to use for conversation services.
    | Options: 'mock' or 'openai'
    |
    | - mock: Uses rule-based responses (free, instant, good for testing)
    | - openai: Uses OpenAI GPT-4 (requires API key, costs ~$0.002/turn)
    |
    */

    'provider' => env('AI_PROVIDER', 'mock'),

    /*
    |--------------------------------------------------------------------------
    | OpenAI Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for OpenAI API integration.
    | Only used when provider is set to 'openai'.
    |
    */

    'openai' => [
        // Your OpenAI API key from https://platform.openai.com/api-keys
        'api_key' => env('OPENAI_API_KEY', 'sk-YOUR_KEY_HERE'),

        // OpenAI model to use
        // Options: 'gpt-4-turbo-preview', 'gpt-4', 'gpt-3.5-turbo'
        'model' => env('OPENAI_MODEL', 'gpt-4-turbo-preview'),

        // Maximum tokens in response
        'max_tokens' => env('OPENAI_MAX_TOKENS', 500),

        // Temperature (0.0 to 1.0) - lower is more focused, higher is more creative
        'temperature' => env('OPENAI_TEMPERATURE', 0.7),

        // Request timeout in seconds
        'timeout' => env('OPENAI_TIMEOUT', 30),

        // Maximum retries on failure
        'max_retries' => env('OPENAI_MAX_RETRIES', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | Mock AI Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for mock AI services.
    | Used for testing without external API calls.
    |
    */

    'mock' => [
        // Simulated processing delay in milliseconds (for realistic testing)
        'delay_ms' => env('MOCK_AI_DELAY_MS', 100),

        // Default confidence score for mock responses
        'default_confidence' => 0.85,

        // Enable verbose logging for debugging
        'verbose' => env('MOCK_AI_VERBOSE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Intent Recognition Settings
    |--------------------------------------------------------------------------
    |
    | Settings for intent classification.
    |
    */

    'intent' => [
        // Minimum confidence threshold for accepting an intent
        'confidence_threshold' => env('INTENT_CONFIDENCE_THRESHOLD', 0.7),

        // Whether to use conversation history for better context
        'use_history' => env('INTENT_USE_HISTORY', true),

        // Maximum conversation history to include (number of turns)
        'max_history_turns' => env('INTENT_MAX_HISTORY', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Entity Extraction Settings
    |--------------------------------------------------------------------------
    |
    | Settings for entity extraction from user input.
    |
    */

    'entity' => [
        // Minimum confidence for accepting extracted entities (OpenAI only)
        'confidence_threshold' => env('ENTITY_CONFIDENCE_THRESHOLD', 0.6),

        // Date format for extracted dates
        'date_format' => 'Y-m-d',

        // Time format for extracted times
        'time_format' => 'H:i',

        // Phone number format
        'phone_format' => 'E.164', // International format with country code
    ],

    /*
    |--------------------------------------------------------------------------
    | Response Generation Settings
    |--------------------------------------------------------------------------
    |
    | Settings for generating AI responses.
    |
    */

    'response' => [
        // Maximum response length in characters
        'max_length' => env('RESPONSE_MAX_LENGTH', 500),

        // Response style: 'professional', 'friendly', 'concise'
        'style' => env('RESPONSE_STYLE', 'professional'),

        // Whether to use LLM for response generation (vs templates)
        // Applies to OpenAI provider only - Mock always uses templates
        'use_llm' => env('RESPONSE_USE_LLM', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Error Handling
    |--------------------------------------------------------------------------
    |
    | How to handle AI service errors.
    |
    */

    'error_handling' => [
        // Fallback to mock provider if OpenAI fails
        'fallback_to_mock' => env('AI_FALLBACK_TO_MOCK', true),

        // Retry failed requests
        'retry_on_failure' => env('AI_RETRY_ON_FAILURE', true),

        // Log all AI requests and responses
        'log_requests' => env('AI_LOG_REQUESTS', true),
    ],

];
