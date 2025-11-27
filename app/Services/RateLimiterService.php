<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Exception;

class RateLimiterService
{
    private array $limits;

    public function __construct()
    {
        $this->limits = [
            'openai' => config('article-generator.rate_limits.openai_rpm', 500),
            'google' => config('article-generator.rate_limits.google_rpm', 1000),
        ];
    }

    public function checkLimit(string $service): void
    {
        $key = "rate_limit:{$service}:" . now()->format('YmdHi');
        $current = Cache::get($key, 0);

        if ($current >= $this->limits[$service]) {
            $waitTime = 60 - now()->second;
            Log::warning("Rate limit reached for {$service}, waiting {$waitTime} seconds");
            throw new Exception("Rate limit exceeded for {$service}. Please try again in {$waitTime} seconds.");
        }
    }

    public function recordRequest(string $service): void
    {
        $key = "rate_limit:{$service}:" . now()->format('YmdHi');
        $current = Cache::get($key, 0);
        Cache::put($key, $current + 1, 120);
    }

    public function getStatus(string $service): array
    {
        $key = "rate_limit:{$service}:" . now()->format('YmdHi');
        $current = Cache::get($key, 0);
        $limit = $this->limits[$service];

        return [
            'service' => $service,
            'current' => $current,
            'limit' => $limit,
            'remaining' => max(0, $limit - $current),
            'reset_at' => now()->endOfMinute()->toISOString()
        ];
    }
}
