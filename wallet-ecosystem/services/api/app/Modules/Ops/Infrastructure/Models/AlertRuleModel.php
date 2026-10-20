<?php

declare(strict_types=1);

namespace App\Modules\Ops\Infrastructure\Models;

use App\Modules\Ops\Domain\Enums\AlertSeverity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string                         $id
 * @property string                         $name
 * @property string|null                    $description
 * @property array                          $metric_condition
 * @property AlertSeverity                  $severity
 * @property array                          $notification_channels
 * @property string|null                    $grouping_key
 * @property bool                           $is_enabled
 * @property string                         $created_by
 * @property string|null                    $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
final class AlertRuleModel extends Model
{
    public    $incrementing = false;
    protected $keyType      = 'string';
    protected $connection   = 'platform';
    protected $table        = 'ops.alert_rules';

    protected $fillable = [
        'id', 'name', 'description', 'metric_condition', 'severity',
        'notification_channels', 'grouping_key', 'is_enabled', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'metric_condition'     => 'array',
        'notification_channels'=> 'array',
        'is_enabled'           => 'boolean',
        'severity'             => AlertSeverity::class,
    ];

    public function alerts(): HasMany
    {
        return $this->hasMany(AlertModel::class, 'alert_rule_id');
    }
}
