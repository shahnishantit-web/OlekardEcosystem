<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Ramsey\Uuid\Uuid;

/**
 * @property string $id
 * @property string $schedule_id
 * @property string|null $job_id
 * @property string $outcome
 * @property string|null $failure_reason
 * @property \Illuminate\Support\Carbon|null $triggered_at
 */
final class ScheduleRunModel extends Model
{
    protected $connection = 'platform';
    protected $table      = 'reporting.schedule_runs';

    public $timestamps = false;

    protected $fillable = [
        'schedule_id', 'job_id', 'outcome', 'failure_reason', 'triggered_at',
    ];

    protected $casts = [
        'triggered_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(static function (self $model): void {
            $model->id           ??= (string) Uuid::uuid7();
            $model->triggered_at ??= now();
        });

        static::updating(static function (): void {
            throw new \RuntimeException('schedule_run_immutable');
        });

        static::deleting(static function (): void {
            throw new \RuntimeException('schedule_run_immutable');
        });
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ReportScheduleModel::class, 'schedule_id');
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(ReportJobModel::class, 'job_id');
    }
}
