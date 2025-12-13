<?php
// app/Services/RateLimitControlService.php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

class RateLimitControlService
{
    private const RATE_LIMIT_ENABLED_KEY = 'rate_limit_enabled';

    /**
     * Check if rate limiting is enabled
     */
    public function isEnabled(): bool
    {
        return Cache::get(self::RATE_LIMIT_ENABLED_KEY, true);
    }

    /**
     * Enable rate limiting
     */
    public function enable(): void
    {
        Cache::forever(self::RATE_LIMIT_ENABLED_KEY, true);
    }

    /**
     * Disable rate limiting
     */
    public function disable(): void
    {
        Cache::forever(self::RATE_LIMIT_ENABLED_KEY, false);
    }

    /**
     * Toggle rate limiting
     */
    public function toggle(): bool
    {
        $currentState = $this->isEnabled();
        $newState = !$currentState;

        Cache::forever(self::RATE_LIMIT_ENABLED_KEY, $newState);

        return $newState;
    }
}
