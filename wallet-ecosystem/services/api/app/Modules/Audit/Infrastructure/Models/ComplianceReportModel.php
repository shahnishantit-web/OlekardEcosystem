<?php

declare(strict_types=1);

namespace App\Modules\Audit\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Ramsey\Uuid\Uuid;

/**
 * @property string $id
 * @property string $report_type
 * @property string $status
 * @property string $requested_by_actor_id
 * @property string|null $date_range_start
 * @property string|null $date_range_end
 * @property array|null  $filters
 * @property array|null  $report_data
 * @property \Carbon\Carbon|null $generated_at
 * @property \Carbon\Carbon|null $expires_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class ComplianceReportModel extends Model
{
    protected $connection = 'platform';

    protected $table = 'audit.compliance_reports';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'filters'     => 'array',
        'report_data' => 'array',
        'generated_at' => 'datetime',
        'expires_at'   => 'datetime',
        'created_at'   => 'datetime',
        'updated_at'   => 'datetime',
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
}
