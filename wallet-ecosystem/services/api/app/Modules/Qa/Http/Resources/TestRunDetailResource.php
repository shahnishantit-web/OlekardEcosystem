<?php

declare(strict_types=1);

namespace App\Modules\Qa\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TestRunDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $results = $this->resource->results->map(fn ($result) => [
            'id'             => $result->id,
            'test_case_code' => $result->testCase?->code,
            'test_case_name' => $result->testCase?->name,
            'scenario_type'  => $result->testCase?->scenario_type instanceof \App\Modules\Qa\Domain\Enums\ScenarioType
                ? $result->testCase->scenario_type->value
                : $result->testCase?->scenario_type,
            'outcome'        => $result->outcome instanceof \App\Modules\Qa\Domain\Enums\TestResultOutcome
                ? $result->outcome->value
                : $result->outcome,
            'duration_ms'    => $result->duration_ms,
            'error_message'  => $result->error_message,
            'evidence'       => $result->evidence,
            'created_at'     => $result->created_at?->toIso8601String(),
        ]);

        $passed  = $results->where('outcome', 'passed')->count();
        $failed  = $results->whereIn('outcome', ['failed', 'error'])->count();
        $skipped = $results->where('outcome', 'skipped')->count();

        return [
            'id'           => $this->id,
            'category'     => $this->category instanceof \App\Modules\Qa\Domain\Enums\QaCategory
                ? $this->category->value
                : $this->category,
            'status'       => $this->status instanceof \App\Modules\Qa\Domain\Enums\TestRunStatus
                ? $this->status->value
                : $this->status,
            'triggered_by' => $this->triggered_by,
            'counts'       => [
                'passed'  => $passed,
                'failed'  => $failed,
                'skipped' => $skipped,
                'total'   => $results->count(),
            ],
            'started_at'   => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at'   => $this->created_at?->toIso8601String(),
            'results'      => $results->values()->all(),
        ];
    }
}
