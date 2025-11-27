<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ApiKeyMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $apiKey = $this->extractApiKey($request);

        if (empty($apiKey)) {
            Log::warning('API key missing', [
                'ip' => $request->ip(),
                'endpoint' => $request->path()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'API key is required'
            ], 401);
        }

        $validApiKeys = array_filter(explode(',', config('article-generator.api_keys', '')));

        if (empty($validApiKeys)) {
            Log::warning('No API keys configured');
            
            return response()->json([
                'success' => false,
                'message' => 'API authentication not configured'
            ], 500);
        }

        if (!in_array($apiKey, $validApiKeys, true)) {
            Log::warning('Invalid API key attempt', [
                'ip' => $request->ip(),
                'endpoint' => $request->path(),
                'user_agent' => $request->userAgent()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Invalid API key'
            ], 401);
        }

        Log::debug('API key authenticated', [
            'ip' => $request->ip(),
            'endpoint' => $request->path()
        ]);

        return $next($request);
    }

    private function extractApiKey(Request $request): ?string
    {
        $authHeader = $request->header('Authorization');
        if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
            return substr($authHeader, 7);
        }

        $apiKeyHeader = $request->header('X-API-Key');
        if ($apiKeyHeader) {
            return $apiKeyHeader;
        }

        return $request->query('api_key');
    }
}
