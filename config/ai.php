<?php


return [

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

        // Retry failed requests
        'retry_on_failure' => env('AI_RETRY_ON_FAILURE', true),

        // Log all AI requests and responses
        'log_requests' => env('AI_LOG_REQUESTS', true),
    ],

];
