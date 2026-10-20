<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $idempotency_key
 * @property string $actor_id
 * @property string $actor_type
 * @property string $request_hash
 * @property string $http_method
 * @property string $http_path
 * @property string $status
 * @property int|null $response_status
 * @property array|null $response_body
 * @property \Carbon\Carbon|null $processing_started_at
 * @property int $attempt_count
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property \Carbon\Carbon $expires_at
 */
final class IdempotencyKeyModel extends Model
{
    protected $connection = 'platform';

    protected $table = 'platform.idempotency_records';

    public $timestamps = false;

    protected $fillable = [
        'id',
        'idempotency_key',
        'actor_id',
        'actor_type',
        'request_hash',
        'http_method',
        'http_path',
        'status',
        'response_status',
        'response_body',
        'processing_started_at',
        'attempt_count',
        'created_at',
        'updated_at',
        'expires_at',
    ];

    protected $casts = [
        'response_body'          => 'array',
        'processing_started_at'  => 'datetime',
        'created_at'             => 'datetime',
        'updated_at'             => 'datetime',
        'expires_at'             => 'datetime',
    ];

    public function scopeForActor(Builder $query, string $actorId): Builder
    {
        return $query->where('actor_id', $actorId);
    }
}
