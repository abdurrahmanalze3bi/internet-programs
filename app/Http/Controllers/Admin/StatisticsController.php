<?php
// app/Http/Controllers/Admin/StatisticsController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Repositories\StatisticsRepository;
use App\Services\Export\ExportService;
use App\Services\Export\CsvExportStrategy;
use App\Services\Export\PdfExportStrategy;
use App\Services\RateLimitControlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Carbon\Carbon;

class StatisticsController extends Controller
{
    public function __construct(
        private StatisticsRepository $statisticsRepository,
        private ExportService $exportService,
        private RateLimitControlService $rateLimitControl
    ) {}

    /**
     * Get overall system statistics
     */
    public function overview(Request $request): JsonResponse
    {
        $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);

        $startDate = $request->start_date ? Carbon::parse($request->start_date) : null;
        $endDate = $request->end_date ? Carbon::parse($request->end_date) : null;

        $stats = $this->statisticsRepository->getOverallStats($startDate, $endDate);

        return response()->json([
            'data' => $stats,
            'period' => [
                'start' => $startDate?->format('Y-m-d'),
                'end' => $endDate?->format('Y-m-d'),
            ],
        ]);
    }

    /**
     * Get statistics by entity
     */
    public function byEntity(Request $request): JsonResponse
    {
        $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);

        $startDate = $request->start_date ? Carbon::parse($request->start_date) : null;
        $endDate = $request->end_date ? Carbon::parse($request->end_date) : null;

        $stats = $this->statisticsRepository->getStatsByEntity($startDate, $endDate);

        return response()->json([
            'data' => $stats,
        ]);
    }

    /**
     * Get complaints trend
     */
    public function trend(Request $request): JsonResponse
    {
        $request->validate([
            'period' => ['nullable', 'in:daily,weekly,monthly'],
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $period = $request->input('period', 'daily');
        $days = $request->input('days', 30);

        $trend = $this->statisticsRepository->getComplaintsTrend($period, $days);

        return response()->json([
            'data' => $trend,
        ]);
    }

    /**
     * Get top performing entities
     */
    public function topEntities(Request $request): JsonResponse
    {
        $limit = $request->input('limit', 10);

        $topEntities = $this->statisticsRepository->getTopPerformingEntities($limit);

        return response()->json([
            'data' => $topEntities,
        ]);
    }

    /**
     * Export statistics to CSV
     */
    public function exportCsv(Request $request): JsonResponse
    {
        $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);

        $startDate = $request->start_date ? Carbon::parse($request->start_date) : null;
        $endDate = $request->end_date ? Carbon::parse($request->end_date) : null;

        $stats = $this->statisticsRepository->getStatsByEntity($startDate, $endDate);

        $this->exportService->setStrategy(new CsvExportStrategy());
        $filename = $this->exportService->export($stats);

        return response()->json([
            'message' => 'Export successful',
            'download_url' => url('/api/admin/statistics/download/' . basename($filename)),
        ]);
    }

    /**
     * Export statistics to PDF
     */
    public function exportPdf(Request $request): JsonResponse
    {
        $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);

        $startDate = $request->start_date ? Carbon::parse($request->start_date) : null;
        $endDate = $request->end_date ? Carbon::parse($request->end_date) : null;

        $stats = $this->statisticsRepository->getStatsByEntity($startDate, $endDate);

        $this->exportService->setStrategy(new PdfExportStrategy());
        $filename = $this->exportService->export($stats, [
            'title' => 'Complaints Statistics Report',
        ]);

        return response()->json([
            'message' => 'Export successful',
            'download_url' => url('/api/admin/statistics/download/' . basename($filename)),
        ]);
    }

    /**
     * Download exported file
     */
    public function download(string $filename)
    {
        $path = storage_path('app/exports/' . $filename);

        if (!file_exists($path)) {
            return response()->json(['message' => 'File not found'], 404);
        }

        return response()->download($path)->deleteFileAfterSend();
    }

    /**
     * Get activity log (versioning)
     */
    public function activityLog(Request $request): JsonResponse
    {
        $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'model' => ['nullable', 'string'],
        ]);

        $perPage = $request->input('per_page', 50);
        $model = $request->input('model');

        $activities = $this->statisticsRepository->getActivityLog($perPage, $model);

        return response()->json([
            'data' => $activities->map(function($activity) {
                return [
                    'id' => $activity->id,
                    'description' => $activity->description,
                    'subject_type' => class_basename($activity->subject_type),
                    'subject_id' => $activity->subject_id,
                    'causer' => $activity->causer ? [
                        'id' => $activity->causer->id,
                        'name' => $activity->causer->full_name,
                        'role' => $activity->causer->role,
                    ] : null,
                    'properties' => $activity->properties,
                    'created_at' => $activity->created_at,
                ];
            }),
            'meta' => [
                'current_page' => $activities->currentPage(),
                'last_page' => $activities->lastPage(),
                'per_page' => $activities->perPage(),
                'total' => $activities->total(),
            ],
        ]);
    }

    /**
     * Get performance metrics
     */
    public function performance(): JsonResponse
    {
        // Read last 100 lines from performance log
        $logFile = storage_path('logs/performance.log');

        if (!file_exists($logFile)) {
            return response()->json([
                'message' => 'No performance data available',
                'data' => [],
            ]);
        }

        $lines = array_slice(file($logFile), -100);
        $metrics = [];

        foreach ($lines as $line) {
            if (strpos($line, 'Request Performance') !== false) {
                preg_match('/\{(.*)\}/', $line, $matches);
                if (!empty($matches[1])) {
                    $data = json_decode('{' . $matches[1] . '}', true);
                    $metrics[] = $data;
                }
            }
        }

        // Calculate averages
        $avgExecutionTime = collect($metrics)->avg('execution_time_ms');
        $avgMemoryUsage = collect($metrics)->avg('memory_used_mb');

        return response()->json([
            'data' => [
                'recent_requests' => array_slice($metrics, -20), // Last 20 requests
                'averages' => [
                    'execution_time_ms' => round($avgExecutionTime, 2),
                    'memory_used_mb' => round($avgMemoryUsage, 2),
                ],
                'slowest_requests' => collect($metrics)
                    ->sortByDesc('execution_time_ms')
                    ->take(10)
                    ->values(),
            ],
        ]);
    }

    /**
     * Enable rate limiting
     */
    public function enableRateLimiting(): JsonResponse
    {
        $this->rateLimitControl->enable();

        return response()->json([
            'message' => 'Rate limiting enabled',
            'status' => 'enabled',
        ]);
    }

    /**
     * Disable rate limiting
     */
    public function disableRateLimiting(): JsonResponse
    {
        $this->rateLimitControl->disable();

        return response()->json([
            'message' => 'Rate limiting disabled',
            'status' => 'disabled',
        ]);
    }

    /**
     * Toggle rate limiting
     */
    public function toggleRateLimiting(): JsonResponse
    {
        $newState = $this->rateLimitControl->toggle();

        return response()->json([
            'message' => 'Rate limiting ' . ($newState ? 'enabled' : 'disabled'),
            'status' => $newState ? 'enabled' : 'disabled',
        ]);
    }

    /**
     * Get rate limiting status
     */
    public function rateLimitingStatus(): JsonResponse
    {
        $isEnabled = $this->rateLimitControl->isEnabled();

        return response()->json([
            'status' => $isEnabled ? 'enabled' : 'disabled',
            'message' => 'Rate limiting is currently ' . ($isEnabled ? 'enabled' : 'disabled'),
        ]);
    }
}
