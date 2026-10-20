<?php

declare(strict_types=1);

namespace App\Modules\Audit\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Ramsey\Uuid\Uuid;

/**
 * @property string $id
 * @property string $data_category
 * @property int    $minimum_retention_days
 * @property int|null $previous_minimum_retention_days
 * @property string $post_retention_action
 * @property \Carbon\Carbon $effective_from
 * @property \Carbon\Carbon|\Carbon\CarbonImmutable|null $enforcement_hold_until
 * @property string $configured_by_actor_id
 */
final class RetentionPolicyModel extends Model
{
    protected $connection = 'platform';
    protected $table      = 'audit.retention_policies';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'data_category',
        'minimum_retention_days',
        'post_retention_action',
        'effective_from',
        'enforcement_hold_until',
        'configured_by_actor_id',
        'previous_minimum_retention_days',
    ];

    protected $casts = [
        'effective_from'                  => 'date',
        'enforcement_hold_until'          => 'date',
        'minimum_retention_days'          => 'integer',
        'previous_minimum_retention_days' => 'integer',
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
