<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;

final class OutboxEventModel extends Model
{
    protected $connection = 'platform';

    protected $table = 'platform.outbox_events';

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'actor_id',
        'aggregate_id',
        'aggregate_type',
        'correlation_id',
        'causation_id',
        'event_name',
        'event_version',
        'payload',
        'metadata',
        'status',
        'attempts',
        'max_attempts',
        'next_attempt_at',
        'last_error',
        'processing_started_at',
        'created_at',
        'delivered_at',
    ];

    protected $casts = [
        'payload'               => 'array',
        'metadata'              => 'array',
        'next_attempt_at'       => 'datetime',
        'processing_started_at' => 'datetime',
        'delivered_at'          => 'datetime',
        'created_at'            => 'datetime',
    ];
}
