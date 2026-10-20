<?php

declare(strict_types=1);

namespace App\Modules\Notification\Infrastructure\Models;

use App\Modules\Notification\Domain\Enums\DeliveryStatus;
use App\Modules\Notification\Domain\Enums\NotificationChannel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string                   $id
 * @property string                   $notification_id
 * @property NotificationChannel      $channel
 * @property DeliveryStatus           $status
 * @property int                      $attempt_count
 * @property \Carbon\Carbon|null      $last_attempt_at
 * @property \Carbon\Carbon|null      $next_retry_at
 * @property string|null              $provider_ref
 * @property string|null              $error_code
 * @property string|null              $error_message
 * @property \Carbon\Carbon|null      $created_at
 * @property \Carbon\Carbon|null      $updated_at
 */
final class NotificationDeliveryModel extends Model
{
    protected $connection = 'notification';

    protected $table = 'notification.notification_deliveries';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'notification_id',
        'channel',
        'status',
        'attempt_count',
        'last_attempt_at',
        'next_retry_at',
        'provider_ref',
        'error_code',
        'error_message',
    ];

    protected $casts = [
        'channel'         => NotificationChannel::class,
        'status'          => DeliveryStatus::class,
        'attempt_count'   => 'integer',
        'last_attempt_at' => 'datetime',
        'next_retry_at'   => 'datetime',
        'created_at'      => 'datetime',
        'updated_at'      => 'datetime',
    ];

    public function notification(): BelongsTo
    {
        return $this->belongsTo(NotificationModel::class, 'notification_id');
    }
}
