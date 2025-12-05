<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Speech-to-Text (STT) Configuration
    |--------------------------------------------------------------------------
    */
    'stt' => [
        'provider' => env('STT_PROVIDER', 'mock'), // mock|deepgram|azure
        'mock' => env('STT_MOCK', true),

        'deepgram' => [
            'api_key' => env('DEEPGRAM_API_KEY'),
            'model' => env('DEEPGRAM_MODEL', 'nova-2'),
            'language' => env('DEEPGRAM_LANGUAGE', 'en-US'),
            'endpointing' => env('DEEPGRAM_ENDPOINTING', 300),
        ],

        'azure' => [
            'subscription_key' => env('AZURE_SPEECH_KEY'),
            'region' => env('AZURE_SPEECH_REGION', 'eastus'),
            'language' => env('AZURE_SPEECH_LANGUAGE', 'en-US'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Text-to-Speech (TTS) Configuration
    |--------------------------------------------------------------------------
    */
    'tts' => [
        'provider' => env('TTS_PROVIDER', 'mock'), // mock|elevenlabs|azure
        'mock' => env('TTS_MOCK', true),

        'elevenlabs' => [
            'api_key' => env('ELEVENLABS_API_KEY'),
            'voice_id' => env('ELEVENLABS_VOICE_ID'),
            'model' => env('ELEVENLABS_MODEL', 'eleven_monolingual_v1'),
        ],

        'azure' => [
            'subscription_key' => env('AZURE_SPEECH_KEY'),
            'region' => env('AZURE_SPEECH_REGION', 'eastus'),
            'voice_name' => env('AZURE_TTS_VOICE', 'en-US-JennyNeural'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Large Language Model (LLM) Configuration
    |--------------------------------------------------------------------------
    */
    'llm' => [
        'provider' => env('LLM_PROVIDER', 'mock'), // mock|openai|anthropic
        'mock' => env('LLM_MOCK', true),

        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_MODEL', 'gpt-4'),
            'max_tokens' => env('OPENAI_MAX_TOKENS', 500),
            'temperature' => env('OPENAI_TEMPERATURE', 0.7),
        ],

        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'model' => env('ANTHROPIC_MODEL', 'claude-3-sonnet-20240229'),
            'max_tokens' => env('ANTHROPIC_MAX_TOKENS', 500),
        ],
    ],
];
