<?php
// app/Repositories/StatisticsRepository.php

namespace App\Repositories;

use App\Models\Complaint;
use App\Models\Entity;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class StatisticsRepository
{
    /**
     * Get overall system statistics
     */
    public function getOverallStats(?Carbon $startDate = null, ?Carbon $endDate = null): array
    {
        $query = Complaint::query();

        if ($startDate && $endDate) {
            $query->whereBetween('created_at', [$startDate, $endDate]);
        }

        return [
            'total_entities' => Entity::count(),
            'active_entities' => Entity::where('is_active', true)->count(),
            'total_employees' => User::where('role', 'employee')->count(),
            'active_employees' => User::where('role', 'employee')->where('is_active', true)->count(),
            'total_citizens' => User::where('role', 'citizen')->count(),
            'total_complaints' => $query->count(),
            'complaints_by_status' => [
                'new' => (clone $query)->where('status', 'new')->count(),
                'in_progress' => (clone $query)->where('status', 'in_progress')->count(),
                'finished' => (clone $query)->where('status', 'finished')->count(),
                'declined' => (clone $query)->where('status', 'declined')->count(),
            ],
            // ❌ REMOVED: complaints_by_priority (column doesn't exist)
            'average_resolution_time' => $this->getAverageResolutionTime($startDate, $endDate),
            'complaints_today' => Complaint::whereDate('created_at', today())->count(),
            'complaints_this_week' => Complaint::whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])->count(),
            'complaints_this_month' => Complaint::whereMonth('created_at', now()->month)->count(),
        ];
    }

    /**
     * Get statistics by entity
     */
    public function getStatsByEntity(?Carbon $startDate = null, ?Carbon $endDate = null): array
    {
        $entities = Entity::withCount(['employees', 'complaints' => function($query) use ($startDate, $endDate) {
            if ($startDate && $endDate) {
                $query->whereBetween('created_at', [$startDate, $endDate]);
            }
        }])->get();

        return $entities->map(function($entity) use ($startDate, $endDate) {
            $query = $entity->complaints();

            if ($startDate && $endDate) {
                $query->whereBetween('created_at', [$startDate, $endDate]);
            }

            return [
                'entity_id' => $entity->id,
                'entity_name' => $entity->name,
                'entity_name_ar' => $entity->name_ar,
                'total_employees' => $entity->employees_count,
                'total_complaints' => $entity->complaints_count,
                'status_breakdown' => [
                    'new' => (clone $query)->where('status', 'new')->count(),
                    'in_progress' => (clone $query)->where('status', 'in_progress')->count(),
                    'finished' => (clone $query)->where('status', 'finished')->count(),
                    'declined' => (clone $query)->where('status', 'declined')->count(),
                ],
                'average_resolution_time' => $this->getEntityAverageResolutionTime($entity->id, $startDate, $endDate),
            ];
        })->toArray();
    }

    /**
     * Get complaints trend (daily/weekly/monthly)
     */
    public function getComplaintsTrend(string $period = 'daily', int $days = 30): array
    {
        $format = match($period) {
            'daily' => '%Y-%m-%d',
            'weekly' => '%Y-%U',
            'monthly' => '%Y-%m',
            default => '%Y-%m-%d',
        };

        return Complaint::select(
            DB::raw("DATE_FORMAT(created_at, '{$format}') as period"),
            DB::raw('COUNT(*) as count'),
            DB::raw('SUM(CASE WHEN status = "finished" THEN 1 ELSE 0 END) as finished'),
            DB::raw('SUM(CASE WHEN status = "declined" THEN 1 ELSE 0 END) as declined'),
            DB::raw('SUM(CASE WHEN status = "in_progress" THEN 1 ELSE 0 END) as in_progress'),
            DB::raw('SUM(CASE WHEN status = "new" THEN 1 ELSE 0 END) as new')
        )
            ->where('created_at', '>=', now()->subDays($days))
            ->groupBy('period')
            ->orderBy('period')
            ->get()
            ->toArray();
    }

    /**
     * Get top performing entities (by resolution rate)
     */
    public function getTopPerformingEntities(int $limit = 10): array
    {
        return Entity::select('entities.*')
            ->withCount([
                'complaints',
                'complaints as finished_complaints_count' => function($query) {
                    $query->where('status', 'finished');
                }
            ])
            ->having('complaints_count', '>', 0)
            ->get()
            ->map(function($entity) {
                $resolutionRate = $entity->complaints_count > 0
                    ? ($entity->finished_complaints_count / $entity->complaints_count) * 100
                    : 0;

                return [
                    'entity_id' => $entity->id,
                    'entity_name' => $entity->name,
                    'total_complaints' => $entity->complaints_count,
                    'finished_complaints' => $entity->finished_complaints_count,
                    'resolution_rate' => round($resolutionRate, 2),
                ];
            })
            ->sortByDesc('resolution_rate')
            ->take($limit)
            ->values()
            ->toArray();
    }

    /**
     * Get average resolution time
     */
    private function getAverageResolutionTime(?Carbon $startDate, ?Carbon $endDate): ?float
    {
        $query = Complaint::whereNotNull('resolved_at');

        if ($startDate && $endDate) {
            $query->whereBetween('created_at', [$startDate, $endDate]);
        }

        $avg = $query->selectRaw('AVG(TIMESTAMPDIFF(HOUR, created_at, resolved_at)) as avg_hours')
            ->value('avg_hours');

        return $avg ? round($avg, 2) : null;
    }

    /**
     * Get entity average resolution time
     */
    private function getEntityAverageResolutionTime(int $entityId, ?Carbon $startDate, ?Carbon $endDate): ?float
    {
        $query = Complaint::where('entity_id', $entityId)
            ->whereNotNull('resolved_at');

        if ($startDate && $endDate) {
            $query->whereBetween('created_at', [$startDate, $endDate]);
        }

        $avg = $query->selectRaw('AVG(TIMESTAMPDIFF(HOUR, created_at, resolved_at)) as avg_hours')
            ->value('avg_hours');

        return $avg ? round($avg, 2) : null;
    }

    /**
     * Get activity log (versioning)
     */
    public function getActivityLog(int $perPage = 50, ?string $model = null)
    {
        $query = \Spatie\Activitylog\Models\Activity::with(['causer', 'subject'])
            ->latest();

        if ($model) {
            $query->where('subject_type', $model);
        }

        return $query->paginate($perPage);
    }
}
