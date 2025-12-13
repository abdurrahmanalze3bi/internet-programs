<?php
// app/Http/Middleware/PerformanceMonitoringMiddleware.php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class PerformanceMonitoringMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        // Start timer
        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        // Execute request
        $response = $next($request);

        // Calculate metrics
        $endTime = microtime(true);
        $endMemory = memory_get_usage();

        $executionTime = ($endTime - $startTime) * 1000; // Convert to milliseconds
        $memoryUsed = ($endMemory - $startMemory) / 1024 / 1024; // Convert to MB

        // Log performance metrics
        Log::channel('performance')->info('Request Performance', [
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'execution_time_ms' => round($executionTime, 2),
            'memory_used_mb' => round($memoryUsed, 2),
            'status_code' => $response->getStatusCode(),
            'user_id' => auth()->id(),
            'ip' => $request->ip(),
        ]);

        // Add headers for debugging
        if (config('app.debug')) {
            $response->headers->set('X-Execution-Time', round($executionTime, 2) . 'ms');
            $response->headers->set('X-Memory-Usage', round($memoryUsed, 2) . 'MB');
        }

        return $response;
    }
}
