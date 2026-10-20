<?php

declare(strict_types=1);

namespace App\Modules\Qa\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TestRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $passed  = $this->resource->results()->where('outcome', 'passed')->count();
        $failed  = $this->resource->results()->whereIn('outcome', ['failed', 'error'])->count();
        $skipped = $this->resource->results()->where('outcome', 'skipped')->count();
        $total   = $passed + $failed + $skipped;

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
                'total'   => $total,
            ],
            'started_at'   => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at'   => $this->created_at?->toIso8601String(),
        ];
    }
}
