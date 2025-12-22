<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Session Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for conversation session management (Redis).
    |
    */

    'session' => [
        // Session TTL (time to live) in seconds
        // Default: 1 hour (3600 seconds)
        'ttl_seconds' => env('SESSION_TTL', 3600),

        // Maximum conversation turns per session
        'max_turns' => env('MAX_CONVERSATION_TURNS', 50),

        // Session key prefix in Redis
        'key_prefix' => env('SESSION_KEY_PREFIX', 'session:'),

        // Auto-extend TTL on each activity
        'auto_extend' => env('SESSION_AUTO_EXTEND', true),

        // Clean up expired sessions automatically
        'auto_cleanup' => env('SESSION_AUTO_CLEANUP', true),

        // Cleanup interval in minutes
        'cleanup_interval' => env('SESSION_CLEANUP_INTERVAL', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Turn-Taking Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for managing conversation turns and timing.
    |
    */

    'turn_taking' => [
        // Delay before responding (milliseconds)
        // Makes conversation feel more natural
        'response_delay_ms' => env('RESPONSE_DELAY_MS', 300),

        // Timeout for user silence (milliseconds)
        // How long to wait before considering user finished speaking
        'silence_timeout_ms' => env('SILENCE_TIMEOUT_MS', 5000),

        // Maximum wait time for user speech (milliseconds)
        'max_wait_for_speech_ms' => env('MAX_WAIT_FOR_SPEECH_MS', 10000),

        // Allow interruptions (user can interrupt AI response)
        'allow_interruptions' => env('ALLOW_INTERRUPTIONS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Transcript Buffer Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for buffering and processing speech transcripts.
    |
    */

    'transcript' => [
        // Only buffer final transcripts (ignore interim results)
        'buffer_final_only' => env('TRANSCRIPT_BUFFER_FINAL', true),

        // Minimum confidence score to accept transcript
        'min_confidence' => env('MIN_TRANSCRIPT_CONFIDENCE', 0.7),

        // Maximum buffer size (number of text segments)
        'max_buffer_size' => env('TRANSCRIPT_MAX_BUFFER', 10),

        // Auto-flush buffer after silence timeout
        'auto_flush' => env('TRANSCRIPT_AUTO_FLUSH', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Dialogue Flow Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for conversation flow and state management.
    |
    */

    'dialogue' => [
        // Initial conversation state
        'initial_state' => 'GREETING',

        // Maximum number of clarification requests per turn
        'max_clarifications' => env('MAX_CLARIFICATIONS', 3),

        // Transfer to human after max clarifications
        'transfer_after_max_clarifications' => env('TRANSFER_AFTER_CLARIFICATIONS', true),

        // States that require human intervention
        'human_required_states' => [
            'TRANSFER_TO_HUMAN',
            'ERROR',
        ],

        // States where user input is optional
        'optional_input_states' => [
            'EXECUTE_BOOKING',
            'EXECUTE_CANCELLATION',
            'EXECUTE_RESCHEDULE',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Prompts and Templates
    |--------------------------------------------------------------------------
    |
    | Pre-defined messages for various conversation scenarios.
    |
    */

    'prompts' => [
        // System prompt file path (for LLM)
        'system_prompt_path' => resource_path('prompts/system.txt'),

        // Greeting message
        'greeting' => env(
            'PROMPT_GREETING',
            'Hello, welcome to {hospital_name}. How can I help you with your appointment today?'
        ),

        // Closing message
        'closing' => env(
            'PROMPT_CLOSING',
            'Is there anything else I can help you with?'
        ),

        // Goodbye message
        'goodbye' => env(
            'PROMPT_GOODBYE',
            'Thank you for calling. Have a great day!'
        ),

        // Clarification request
        'clarification' => env(
            'PROMPT_CLARIFICATION',
            "I'm sorry, I didn't quite understand that. Could you please rephrase?"
        ),

        // Transfer to human
        'transfer' => env(
            'PROMPT_TRANSFER',
            "I'll transfer you to a staff member who can better assist you. Please hold."
        ),

        // Error message
        'error' => env(
            'PROMPT_ERROR',
            "I apologize, but I'm experiencing technical difficulties. Let me transfer you to our staff."
        ),

        // Timeout message
        'timeout' => env(
            'PROMPT_TIMEOUT',
            "I haven't heard from you. Are you still there?"
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Conversation History Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for storing and managing conversation history.
    |
    */

    'history' => [
        // Maximum history size to keep in session
        'max_in_session' => env('HISTORY_MAX_IN_SESSION', 10),

        // Store full conversation in database
        'store_full_history' => env('HISTORY_STORE_FULL', true),

        // Include history when parsing intent
        'include_in_intent_parsing' => env('HISTORY_IN_INTENT', true),

        // Include history in context
        'include_in_context' => env('HISTORY_IN_CONTEXT', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Context Management
    |--------------------------------------------------------------------------
    |
    | Settings for conversation context handling.
    |
    */

    'context' => [
        // Maximum context size (number of key-value pairs)
        'max_size' => env('CONTEXT_MAX_SIZE', 50),

        // Auto-clean unused context keys
        'auto_clean' => env('CONTEXT_AUTO_CLEAN', true),

        // Context keys to always preserve
        'preserve_keys' => [
            'patient_id',
            'call_id',
            'intent',
        ],

        // Include timestamp in context
        'include_timestamp' => env('CONTEXT_INCLUDE_TIMESTAMP', true),

        // Include current date/time for entity extraction
        'include_datetime' => env('CONTEXT_INCLUDE_DATETIME', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Validation Settings
    |--------------------------------------------------------------------------
    |
    | Settings for validating collected information.
    |
    */

    'validation' => [
        // Validate entities immediately after extraction
        'validate_entities' => env('VALIDATE_ENTITIES', true),

        // Request confirmation for critical actions
        'confirm_critical_actions' => env('CONFIRM_CRITICAL', true),

        // Critical actions requiring confirmation
        'critical_actions' => [
            'EXECUTE_BOOKING',
            'EXECUTE_CANCELLATION',
            'EXECUTE_RESCHEDULE',
        ],

        // Maximum validation retries
        'max_retries' => env('VALIDATION_MAX_RETRIES', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance Settings
    |--------------------------------------------------------------------------
    |
    | Settings for optimizing conversation performance.
    |
    */

    'performance' => [
        // Enable conversation caching
        'cache_enabled' => env('CONVERSATION_CACHE_ENABLED', true),

        // Cache driver
        'cache_driver' => env('CONVERSATION_CACHE_DRIVER', 'redis'),

        // Cache TTL for frequently accessed data (seconds)
        'cache_ttl' => env('CONVERSATION_CACHE_TTL', 300),

        // Pre-load common responses
        'preload_responses' => env('CONVERSATION_PRELOAD', false),

        // Parallel processing for multiple operations
        'parallel_processing' => env('CONVERSATION_PARALLEL', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Debugging and Development
    |--------------------------------------------------------------------------
    |
    | Settings for debugging conversation flow.
    |
    */

    'debug' => [
        // Enable debug mode (verbose logging)
        'enabled' => env('CONVERSATION_DEBUG', false),

        // Log all state transitions
        'log_state_transitions' => env('LOG_STATE_TRANSITIONS', true),

        // Log all intent detections
        'log_intents' => env('LOG_INTENTS', true),

        // Log all entity extractions
        'log_entities' => env('LOG_ENTITIES', true),

        // Include timing information
        'include_timing' => env('DEBUG_INCLUDE_TIMING', true),
    ],

];
