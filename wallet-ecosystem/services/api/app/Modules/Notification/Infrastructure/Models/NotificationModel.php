<?php

declare(strict_types=1);

namespace App\Modules\Notification\Infrastructure\Models;

use App\Modules\Notification\Domain\Enums\NotificationCategory;
use App\Modules\Notification\Domain\Enums\NotificationStatus;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $source_event_id
 * @property string $source_event_type
 * @property string $notification_type
 * @property NotificationCategory $category
 * @property bool $mandatory
 * @property string $recipient_id
 * @property string $subject
 * @property string $body
 * @property NotificationStatus $status
 * @property Carbon|null $read_at
 * @property string $correlation_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class NotificationModel extends Model
{
    protected $connection = 'notification';

    protected $table = 'notification.notifications';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'source_event_id',
        'source_event_type',
        'notification_type',
        'category',
        'mandatory',
        'recipient_id',
        'subject',
        'body',
        'status',
        'rule_channels',
        'read_at',
        'correlation_id',
    ];

    protected $casts = [
        'rule_channels' => 'array',
        'category' => NotificationCategory::class,
        'status' => NotificationStatus::class,
        'mandatory' => 'boolean',
        'read_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function deliveries(): HasMany
    {
        return $this->hasMany(NotificationDeliveryModel::class, 'notification_id');
    }
}
