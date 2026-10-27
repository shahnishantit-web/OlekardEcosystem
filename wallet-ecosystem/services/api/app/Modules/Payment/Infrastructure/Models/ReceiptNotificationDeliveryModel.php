<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Models;

use App\Modules\Payment\Domain\Enums\ReceiptNotificationDeliveryStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string                                       $id
 * @property string                                       $event_idempotency_key
 * @property string                                       $event_type
 * @property string                                       $payment_intent_id
 * @property string|null                                  $notification_id
 * @property ReceiptNotificationDeliveryStatus            $status
 * @property string|null                                  $template_slug
 * @property string|null                                  $template_version
 * @property \Illuminate\Support\Carbon                   $created_at
 * @property \Illuminate\Support\Carbon                   $updated_at
 */
final class ReceiptNotificationDeliveryModel extends Model
{
    protected $connection = 'payment';
    protected $table      = 'payment.receipt_notification_deliveries';

    public $incrementing = false;
    protected $keyType   = 'string';

    protected $fillable = [
        'id',
        'event_idempotency_key',
        'event_type',
        'payment_intent_id',
        'notification_id',
        'status',
        'template_slug',
        'template_version',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'status'     => ReceiptNotificationDeliveryStatus::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
