<?php

declare(strict_types=1);

namespace App\Modules\Audit\Infrastructure\Models;

use App\Modules\Audit\Domain\Exceptions\AuditImmutabilityViolationException;
use Illuminate\Database\Eloquent\Model;
use Ramsey\Uuid\Uuid;

/**
 * @property string $id
 * @property string $user_id
 * @property string $field_name
 * @property array|null  $before_value
 * @property array|null  $after_value
 * @property string|null $source_system
 * @property string|null $changed_by_actor_id
 * @property string|null $audit_event_id
 * @property bool        $out_of_order
 * @property \Carbon\Carbon $changed_at
 * @property \Carbon\Carbon $created_at
 */
class ComplianceHistoryModel extends Model
{
    protected $connection = 'platform';

    protected $table = 'audit.compliance_history';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'before_value' => 'array',
        'after_value'  => 'array',
        'out_of_order' => 'boolean',
        'changed_at'   => 'datetime',
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

        static::updating(function (): void {
            throw new AuditImmutabilityViolationException();
        });

        static::deleting(function (): void {
            throw new AuditImmutabilityViolationException();
        });
    }
}
