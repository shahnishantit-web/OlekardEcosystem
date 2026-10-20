<?php

declare(strict_types=1);

namespace App\Modules\Notification\Infrastructure\Models;

use App\Modules\Notification\Domain\Enums\NotificationCategory;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $key
 * @property NotificationCategory $category
 * @property string $subject_template
 * @property string $body_template
 * @property array $supported_channels
 * @property array $variables
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class NotificationTemplateModel extends Model
{
    protected $connection = 'notification';

    protected $table = 'notification.notification_templates';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'key',
        'category',
        'subject_template',
        'body_template',
        'supported_channels',
        'variables',
        'active',
        'language',
        'revision',
    ];

    protected $casts = [
        'revision' => 'integer',
        'category' => NotificationCategory::class,
        'supported_channels' => 'array',
        'variables' => 'array',
        'active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
