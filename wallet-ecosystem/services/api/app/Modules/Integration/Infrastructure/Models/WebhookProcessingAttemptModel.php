<?php

declare(strict_types=1);

namespace App\Modules\Integration\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string          $id
 * @property string          $webhook_event_id
 * @property int             $attempt_number
 * @property string          $outcome
 * @property string|null     $error_message
 * @property \Carbon\CarbonInterface  $attempted_at
 */
final class WebhookProcessingAttemptModel extends Model
{
    protected $connection = 'platform';

    protected $table = 'integration.webhook_processing_attempts';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'attempted_at' => 'datetime',
    ];

    public function webhookEvent(): BelongsTo
    {
        return $this->belongsTo(WebhookEventModel::class, 'webhook_event_id');
    }
}
