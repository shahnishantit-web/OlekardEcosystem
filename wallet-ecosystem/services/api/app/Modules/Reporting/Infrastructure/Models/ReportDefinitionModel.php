<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Ramsey\Uuid\Uuid;

/**
 * @property string $id
 * @property string $report_type
 * @property string $display_name
 * @property string $required_permission
 * @property array<string, mixed> $filter_schema
 * @property bool $pii_sensitive
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
final class ReportDefinitionModel extends Model
{
    protected $connection  = 'platform';
    protected $table       = 'reporting.report_definitions';
    protected $guarded     = [];
    protected $keyType     = 'string';
    public    $incrementing = false;

    protected $casts = [
        'filter_schema'  => 'array',
        'pii_sensitive'  => 'boolean',
        'is_active'      => 'boolean',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(static function (self $model): void {
            $model->id ??= (string) Uuid::uuid7();
        });
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
