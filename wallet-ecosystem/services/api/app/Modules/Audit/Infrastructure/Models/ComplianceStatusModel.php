<?php

declare(strict_types=1);

namespace App\Modules\Audit\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Ramsey\Uuid\Uuid;

/**
 * @property string $id
 * @property string $user_id
 * @property int    $kyc_tier
 * @property string $aml_risk_level
 * @property array  $active_restrictions
 * @property \Carbon\Carbon|\Carbon\CarbonImmutable|null $last_changed_at
 * @property string|null $last_audit_event_id
 * @property int    $version
 */
class ComplianceStatusModel extends Model
{
    protected $connection = 'platform';

    protected $table = 'audit.compliance_status';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'active_restrictions' => 'array',
        'version'             => 'integer',
        'kyc_tier'            => 'integer',
        'last_changed_at'     => 'datetime',
        'created_at'          => 'datetime',
        'updated_at'          => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model): void {
            if (empty($model->id)) {
                $model->id = (string) Uuid::uuid7();
            }
        });

        static::saving(function (self $model): ?bool {
            if ($model->exists) {
                // Optimistic lock: increment version on every save
                $affected = static::where('id', $model->id)
                    ->where('version', $model->version)
                    ->update([
                        'kyc_tier'             => $model->kyc_tier,
                        'aml_risk_level'       => $model->aml_risk_level,
                        'active_restrictions'  => json_encode($model->active_restrictions ?? []),
                        'last_changed_at'      => $model->last_changed_at,
                        'last_audit_event_id'  => $model->last_audit_event_id,
                        'version'              => $model->version + 1,
                        'updated_at'           => now(),
                    ]);

                if ($affected === 0) {
                    throw new \RuntimeException('Optimistic lock conflict on compliance_status for user '.$model->user_id);
                }

                $model->version++;
                return false; // prevent normal Eloquent save
            }
            return null;
        });
    }
}
