<?php

declare(strict_types=1);

namespace App\Modules\Notification\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string              $id
 * @property string              $user_id
 * @property array               $push_enabled_categories
 * @property array               $sms_enabled_categories
 * @property array               $email_enabled_categories
 * @property bool                $marketing_opted_in
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 */
final class NotificationPreferenceModel extends Model
{
    protected $connection = 'notification';

    protected $table = 'notification.notification_preferences';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'user_id',
        'push_enabled_categories',
        'sms_enabled_categories',
        'email_enabled_categories',
        'marketing_opted_in',
    ];

    protected $casts = [
        'push_enabled_categories'  => 'array',
        'sms_enabled_categories'   => 'array',
        'email_enabled_categories' => 'array',
        'marketing_opted_in'       => 'boolean',
        'created_at'               => 'datetime',
        'updated_at'               => 'datetime',
    ];
}
