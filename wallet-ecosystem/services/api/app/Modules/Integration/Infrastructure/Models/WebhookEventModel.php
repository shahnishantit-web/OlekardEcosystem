<?php

declare(strict_types=1);

namespace App\Modules\Integration\Infrastructure\Models;

use App\Modules\Integration\Domain\Enums\WebhookEventStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string             $id
 * @property string             $provider_type
 * @property string             $provider_slug
 * @property string             $idempotency_key
 * @property string             $event_type
 * @property string             $raw_payload
 * @property array<string, mixed>|null $verified_notification
 * @property string|null        $payload_hash
 * @property string|null        $encrypted_evidence
 * @property string|null        $source_ip_hash
 * @property WebhookEventStatus $status
 * @property int                $retry_count
 * @property int                $processing_generation
 * @property int                $processing_attempts_count
 * @property string|null        $processing_attempts_max_attempted_at
 * @property int                $max_retry_count
 * @property string|null        $last_error
 * @property string|null        $platform_entity_id
 * @property string|null        $platform_entity_type
 * @property \Carbon\CarbonInterface     $received_at
 * @property \Carbon\CarbonInterface|null $processed_at
 * @property \Carbon\CarbonInterface     $created_at
 * @property \Carbon\CarbonInterface     $updated_at
 */
final class WebhookEventModel extends Model
{
    protected $connection = 'platform';

    protected $table = 'integration.webhook_events';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['raw_payload', 'encrypted_evidence', 'verified_notification'];

    protected $casts = [
        'verified_notification' => 'array',
        'processing_generation' => 'integer',
        'encrypted_evidence' => 'encrypted',
        'status'       => WebhookEventStatus::class,
        'received_at'  => 'datetime',
        'processed_at' => 'datetime',
        'created_at'   => 'datetime',
        'updated_at'   => 'datetime',
    ];

    public function processingAttempts(): HasMany
    {
        return $this->hasMany(WebhookProcessingAttemptModel::class, 'webhook_event_id')
            ->orderBy('attempt_number');
    }
}
