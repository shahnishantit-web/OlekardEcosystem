<?php

declare(strict_types=1);

namespace App\Modules\Qa\Models;

use App\Modules\Qa\Domain\Enums\QaCategory;
use App\Modules\Qa\Domain\Enums\ScenarioType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Ramsey\Uuid\Uuid;

/**
 * @property string       $id
 * @property string       $code
 * @property QaCategory   $category
 * @property string       $name
 * @property string|null  $description
 * @property ScenarioType $scenario_type
 * @property array|null   $expected_outcome
 * @property bool         $is_active
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class TestCase extends Model
{
    protected $connection = 'platform';

    protected $table = 'qa.test_cases';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'category'         => QaCategory::class,
        'scenario_type'    => ScenarioType::class,
        'expected_outcome' => 'array',
        'is_active'        => 'boolean',
        'created_at'       => 'datetime',
        'updated_at'       => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model): void {
            if (empty($model->id)) {
                $model->id = (string) Uuid::uuid7();
            }
        });
    }

    /** @param Builder<TestCase> $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** @param Builder<TestCase> $query */
    public function scopeForCategory(Builder $query, QaCategory $category): Builder
    {
        return $query->where('category', $category->value);
    }
}
