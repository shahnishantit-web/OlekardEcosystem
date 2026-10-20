<?php

declare(strict_types=1);

namespace App\Modules\Qa\Models;

use App\Modules\Qa\Domain\Enums\QaCategory;
use App\Modules\Qa\Domain\Enums\TestRunStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Ramsey\Uuid\Uuid;

/**
 * @property string          $id
 * @property QaCategory      $category
 * @property string          $triggered_by
 * @property TestRunStatus   $status
 * @property string          $idempotency_key
 * @property string          $correlation_id
 * @property \Carbon\Carbon|null $started_at
 * @property \Carbon\Carbon|null $completed_at
 * @property \Carbon\Carbon  $created_at
 */
class TestRun extends Model
{
    protected $connection = 'platform';

    protected $table = 'qa.test_runs';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'category'     => QaCategory::class,
        'status'       => TestRunStatus::class,
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
        'created_at'   => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model): void {
            if (empty($model->id)) {
                $model->id = (string) Uuid::uuid7();
            }
            if (empty($model->created_at)) {
                $model->created_at = now();
            }
        });
    }

    /** @return HasMany<TestResult, TestRun> */
    public function results(): HasMany
    {
        return $this->hasMany(TestResult::class, 'test_run_id');
    }

    public function passedCount(): int
    {
        return $this->results()->where('outcome', 'passed')->count();
    }

    public function failedCount(): int
    {
        return $this->results()->whereIn('outcome', ['failed', 'error'])->count();
    }

    public function skippedCount(): int
    {
        return $this->results()->where('outcome', 'skipped')->count();
    }
}
