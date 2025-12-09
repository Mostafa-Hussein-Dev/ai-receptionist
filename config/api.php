<?php


return [

    /*
    |--------------------------------------------------------------------------
    | API Authentication
    |--------------------------------------------------------------------------
    |
    | Settings for API key authentication.
    |
    */

    'authentication' => [
        // Enable API key authentication
        // Set to false during development, true in production
        'enabled' => env('API_AUTH_ENABLED', true),

        // Header name for API key
        'key_header' => 'X-API-Key',

        // Valid API keys
        // Generate secure keys: php artisan tinker -> Str::random(32)
        'keys' => array_filter([
            env('API_KEY_1', 'your-api-key-1'),
            env('API_KEY_2', 'your-api-key-2'),
        ]),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | API rate limiting configuration.
    |
    */

    'rate_limiting' => [
        // Enable rate limiting
        'enabled' => env('API_RATE_LIMIT_ENABLED', true),

        // Requests per minute per API key
        'requests_per_minute' => env('API_RATE_LIMIT_RPM', 60),

        // Requests per hour per API key
        'requests_per_hour' => env('API_RATE_LIMIT_RPH', 1000),

        // Requests per day per API key
        'requests_per_day' => env('API_RATE_LIMIT_RPD', 10000),
    ],

    /*
    |--------------------------------------------------------------------------
    | API Response Format
    |--------------------------------------------------------------------------
    |
    | Default settings for API responses.
    |
    */

    'response' => [
        // Include metadata in responses (request_id, timestamp, etc.)
        'include_metadata' => env('API_INCLUDE_METADATA', true),

        // Include pagination links in list responses
        'include_pagination_links' => true,

        // Default pagination size
        'per_page' => env('API_PER_PAGE', 15),

        // Maximum pagination size
        'max_per_page' => env('API_MAX_PER_PAGE', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | API Versioning
    |--------------------------------------------------------------------------
    |
    | API version configuration.
    |
    */

    'versioning' => [
        // Current API version
        'current_version' => 'v1',

        // Supported versions
        'supported_versions' => ['v1'],

        // Include version in response headers
        'include_version_header' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | CORS Configuration
    |--------------------------------------------------------------------------
    |
    | Cross-Origin Resource Sharing settings for API.
    |
    */

    'cors' => [
        // Allow CORS requests
        'enabled' => env('API_CORS_ENABLED', true),

        // Allowed origins (use ['*'] to allow all)
        'allowed_origins' => explode(',', env('API_CORS_ORIGINS', '*')),

        // Allowed HTTP methods
        'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],

        // Allowed headers
        'allowed_headers' => ['Content-Type', 'X-API-Key', 'Authorization'],

        // Allow credentials
        'allow_credentials' => false,

        // Max age for preflight cache (seconds)
        'max_age' => 86400,
    ],

    /*
    |--------------------------------------------------------------------------
    | Error Handling
    |--------------------------------------------------------------------------
    |
    | API error response configuration.
    |
    */

    'errors' => [
        // Include stack traces in error responses
        // NEVER enable in production!
        'include_trace' => env('API_ERROR_INCLUDE_TRACE', false),

        // Include exception messages in responses
        'include_exception_message' => env('API_ERROR_INCLUDE_MESSAGE', true),

        // Log all API errors
        'log_errors' => env('API_ERROR_LOG', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Request Logging
    |--------------------------------------------------------------------------
    |
    | Log API requests for monitoring and debugging.
    |
    */

    'logging' => [
        // Enable request logging
        'enabled' => env('API_LOGGING_ENABLED', true),

        // Log request body
        'log_request_body' => env('API_LOG_REQUEST_BODY', false),

        // Log response body
        'log_response_body' => env('API_LOG_RESPONSE_BODY', false),

        // Log headers (excluding sensitive ones)
        'log_headers' => env('API_LOG_HEADERS', true),

        // Headers to exclude from logs
        'exclude_headers' => [
            'Authorization',
            'X-API-Key',
            'Cookie',
            'Set-Cookie',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Settings
    |--------------------------------------------------------------------------
    |
    | API response caching configuration.
    |
    */

    'cache' => [
        // Enable response caching
        'enabled' => env('API_CACHE_ENABLED', true),

        // Cache driver
        'driver' => env('API_CACHE_DRIVER', 'redis'),

        // Cache TTL in seconds
        'ttl' => env('API_CACHE_TTL', 300), // 5 minutes

        // Cache key prefix
        'prefix' => 'api_cache:',

        // Cache specific endpoints
        'cacheable_endpoints' => [
            'GET /api/v1/doctors' => 3600,        // 1 hour
            'GET /api/v1/doctors/{id}' => 3600,   // 1 hour
            'GET /api/v1/slots' => 300,           // 5 minutes
        ],
    ],

];
