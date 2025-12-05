<?php
// ============================================================================
// FILE: ValidateApiKey.php
// Location: app/Http/Middleware/ValidateApiKey.php
// ============================================================================

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateApiKey
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Check if authentication is enabled
        if (!config('api.authentication.enabled', true)) {
            return $next($request);
        }

        // Get API key from header
        $headerName = config('api.authentication.key_header', 'X-API-Key');
        $providedKey = $request->header($headerName);

        if (!$providedKey) {
            return response()->json([
                'success' => false,
                'error' => 'API key required',
                'message' => "Please provide API key in {$headerName} header",
            ], 401);
        }

        // Validate against configured keys
        $validKeys = config('api.authentication.keys', []);

        if (empty($validKeys)) {
            \Log::warning('[API] No API keys configured - authentication disabled');
            return $next($request);
        }

        if (!in_array($providedKey, $validKeys)) {
            \Log::warning('[API] Invalid API key provided', [
                'key' => substr($providedKey, 0, 8) . '...',
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Invalid API key',
            ], 403);
        }

        // Valid key - proceed
        return $next($request);
    }
}
