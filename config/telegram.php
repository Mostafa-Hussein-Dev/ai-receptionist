<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Session Configuration
    |--------------------------------------------------------------------------
    */
    'session' => [
        'ttl_seconds' => env('SESSION_TTL', 3600), // 1 hour
        'max_turns' => env('MAX_CONVERSATION_TURNS', 50),
    ],

    /*
    |--------------------------------------------------------------------------
    | Turn-Taking Configuration
    |--------------------------------------------------------------------------
    */
    'turn_taking' => [
        'response_delay_ms' => env('RESPONSE_DELAY_MS', 300),
        'silence_timeout_ms' => env('SILENCE_TIMEOUT_MS', 5000),
        'max_wait_for_speech_ms' => env('MAX_WAIT_FOR_SPEECH_MS', 10000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Transcript Configuration
    |--------------------------------------------------------------------------
    */
    'transcript' => [
        'buffer_final_only' => true,
        'min_confidence' => env('MIN_TRANSCRIPT_CONFIDENCE', 0.7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Conversation Prompts
    |--------------------------------------------------------------------------
    */
    'prompts' => [
        'system_prompt_path' => resource_path('prompts/system.txt'),

        'greeting' => "Thank you for calling {hospital_name}. How may I help you today?",

        'closing' => "Is there anything else I can help you with?",

        'goodbye' => "Thank you for calling. Have a great day!",

        'clarification' => "I'm sorry, I didn't quite understand that. Could you please rephrase?",

        'transfer' => "I'll transfer you to a staff member who can better assist you. Please hold.",

        'error' => "I'm experiencing a technical difficulty. Let me connect you with our staff. Please hold.",
    ],
];
