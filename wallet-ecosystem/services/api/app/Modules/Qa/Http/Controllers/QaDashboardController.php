<?php

declare(strict_types=1);

namespace App\Modules\Qa\Http\Controllers;

use App\Modules\Qa\Domain\Enums\QaCategory;
use App\Modules\Qa\Http\Resources\DashboardResource;
use App\Modules\Qa\Models\TestRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

final class QaDashboardController extends Controller
{
    public function index(): JsonResponse
    {
        // Latest run per category (single query with window function)
        $latestRuns = DB::connection('platform')
            ->table('qa.test_runs')
            ->select(DB::raw('DISTINCT ON (category) *'))
            ->orderBy('category')
            ->orderBy('created_at', 'desc')
            ->get()
            ->keyBy('category');

        // Aggregate counts per run
        $runIds = $latestRuns->pluck('id')->filter()->toArray();
        $counts = [];

        if (!empty($runIds)) {
            $raw = DB::connection('platform')
                ->table('qa.test_results')
                ->selectRaw("
                    test_run_id,
                    COUNT(*) FILTER (WHERE outcome = 'passed') as passed,
                    COUNT(*) FILTER (WHERE outcome IN ('failed','error')) as failed,
                    COUNT(*) as total
                ")
                ->whereIn('test_run_id', $runIds)
                ->groupBy('test_run_id')
                ->get()
                ->keyBy('test_run_id');

            foreach ($raw as $runId => $row) {
                $counts[$runId] = [
                    'passed' => (int) $row->passed,
                    'failed' => (int) $row->failed,
                    'total'  => (int) $row->total,
                ];
            }
        }

        $categories = [];
        foreach (QaCategory::cases() as $category) {
            $run   = $latestRuns->get($category->value);
            $count = $run ? ($counts[$run->id] ?? ['passed' => 0, 'failed' => 0, 'total' => 0]) : null;

            $categories[] = [
                'category'       => $category->value,
                'label'          => $category->label(),
                'latest_run_id'  => $run?->id,
                'latest_status'  => $run?->status,
                'passed'         => $count['passed'] ?? null,
                'failed'         => $count['failed'] ?? null,
                'total'          => $count['total'] ?? null,
                'last_run_at'    => $run?->created_at,
            ];
        }

        $summary = [
            'total_categories'    => count(QaCategory::cases()),
            'categories_with_run' => $latestRuns->count(),
        ];

        return response()->json([
            'data' => new DashboardResource((object) [
                'summary'    => $summary,
                'categories' => $categories,
            ]),
        ]);
    }
}
