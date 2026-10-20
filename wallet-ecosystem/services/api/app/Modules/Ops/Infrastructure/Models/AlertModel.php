<?php

declare(strict_types=1);

namespace App\Modules\Ops\Infrastructure\Models;

use App\Modules\Ops\Domain\Enums\AlertStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string                          $id
 * @property string                          $alert_rule_id
 * @property AlertStatus                     $status
 * @property string                          $condition_summary
 * @property \Illuminate\Support\Carbon      $fired_at
 * @property \Illuminate\Support\Carbon|null $resolved_at
 * @property \Illuminate\Support\Carbon|null $notification_sent_at
 * @property \Illuminate\Support\Carbon|null $resolution_notification_sent_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read AlertRuleModel|null        $rule
 */
final class AlertModel extends Model
{
    public    $incrementing = false;
    protected $keyType      = 'string';
    protected $connection   = 'platform';
    protected $table        = 'ops.alerts';

    protected $fillable = [
        'id', 'alert_rule_id', 'status', 'condition_summary',
        'fired_at', 'resolved_at', 'notification_sent_at', 'resolution_notification_sent_at',
    ];

    protected $casts = [
        'status'                           => AlertStatus::class,
        'fired_at'                         => 'datetime',
        'resolved_at'                      => 'datetime',
        'notification_sent_at'             => 'datetime',
        'resolution_notification_sent_at'  => 'datetime',
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AlertRuleModel::class, 'alert_rule_id');
    }
}
