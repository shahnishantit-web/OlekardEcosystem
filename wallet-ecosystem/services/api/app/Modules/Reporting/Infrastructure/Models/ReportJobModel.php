<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Models;

use App\Modules\Reporting\Domain\Enums\ReportJobStatus;
use Illuminate\Database\Eloquent\Model;
use Ramsey\Uuid\Uuid;

/**
 * @property string $id
 * @property string|null $definition_id
 * @property string $correlation_id
 * @property string $report_type
 * @property string $requested_by_user_id
 * @property \App\Modules\Reporting\Domain\Enums\ReportJobStatus $status
 * @property array<string, mixed> $filters
 * @property array<string, mixed>|null $result_data
 * @property bool $pii_access
 * @property int|null $row_count
 * @property string|null $failure_reason
 * @property \Illuminate\Support\Carbon|null $as_of_at
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property \Illuminate\Support\Carbon|null $created_at
 */
final class ReportJobModel extends Model
{
    protected $connection  = 'platform';
    protected $table       = 'reporting.report_jobs';
    protected $guarded     = [];
    protected $keyType     = 'string';
    public    $incrementing = false;

    public $timestamps = false;

    protected $casts = [
        'filters'     => 'array',
        'result_data' => 'array',
        'pii_access'  => 'boolean',
        'status'      => ReportJobStatus::class,
        'created_at'  => 'datetime',
        'started_at'  => 'datetime',
        'completed_at' => 'datetime',
        'as_of_at'    => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(static function (self $model): void {
            if (empty($model->id)) {
                $model->id = (string) Uuid::uuid7();
            }
            if (empty($model->created_at)) {
                $model->created_at = now();
            }
        });
    }

    public function transitionTo(ReportJobStatus $next): void
    {
        if (!$this->status->canTransitionTo($next)) {
            throw new \LogicException(
                "Invalid status transition from [{$this->status->value}] to [{$next->value}]."
            );
        }

        $this->status = $next;
        $this->save();
    }
}
