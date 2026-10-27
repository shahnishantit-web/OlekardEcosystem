<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Models;

use App\Modules\Payment\Domain\Enums\ConfigChangeDraftStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string                   $id
 * @property string                   $provider_id
 * @property string                   $change_type
 * @property array<string, mixed>     $change_payload
 * @property ConfigChangeDraftStatus  $status
 * @property string                   $drafted_by_actor_id
 * @property string|null              $reviewed_by_actor_id
 * @property Carbon|null              $reviewed_at
 * @property string|null              $review_notes
 * @property string                   $idempotency_key
 * @property string                   $correlation_id
 * @property int|null                 $account_config_version_at_draft_time
 * @property Carbon|null              $created_at
 * @property Carbon|null              $updated_at
 */
final class PaymentConfigChangeDraftModel extends Model
{
    protected $connection = 'payment';
    protected $table      = 'payment.payment_config_change_drafts';

    public $incrementing = false;
    protected $keyType   = 'string';

    protected $fillable = [
        'id',
        'provider_id',
        'change_type',
        'change_payload',
        'status',
        'drafted_by_actor_id',
        'reviewed_by_actor_id',
        'reviewed_at',
        'review_notes',
        'idempotency_key',
        'correlation_id',
        'account_config_version_at_draft_time',
    ];

    protected function casts(): array
    {
        return [
            'status'                              => ConfigChangeDraftStatus::class,
            'change_payload'                      => 'array',
            'account_config_version_at_draft_time' => 'integer',
            'reviewed_at'                         => 'immutable_datetime',
            'created_at'                          => 'immutable_datetime',
            'updated_at'                          => 'immutable_datetime',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(PaymentProviderModel::class, 'provider_id');
    }
}
