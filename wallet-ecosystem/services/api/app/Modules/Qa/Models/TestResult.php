<?php

declare(strict_types=1);

namespace App\Modules\Qa\Models;

use App\Modules\Qa\Domain\Enums\TestResultOutcome;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Ramsey\Uuid\Uuid;

/**
 * @property string             $id
 * @property string             $test_run_id
 * @property string             $test_case_id
 * @property TestResultOutcome  $outcome
 * @property int|null           $duration_ms
 * @property string|null        $error_message
 * @property array|null         $evidence
 * @property \Carbon\Carbon     $created_at
 */
class TestResult extends Model
{
    protected $connection = 'platform';

    protected $table = 'qa.test_results';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'outcome'    => TestResultOutcome::class,
        'evidence'   => 'array',
        'created_at' => 'datetime',
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

    /** @return BelongsTo<TestRun, TestResult> */
    public function testRun(): BelongsTo
    {
        return $this->belongsTo(TestRun::class, 'test_run_id');
    }

    /** @return BelongsTo<TestCase, TestResult> */
    public function testCase(): BelongsTo
    {
        return $this->belongsTo(TestCase::class, 'test_case_id');
    }
}
