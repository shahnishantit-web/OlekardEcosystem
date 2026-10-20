<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;

final class AuditEventModel extends Model
{
    protected $connection = 'platform';

    protected $table = 'platform.audit_events';

    public $timestamps = false;

    protected $fillable = [
        'id',
        'event_type',
        'actor_id',
        'actor_type',
        'actor_name_masked',
        'resource_type',
        'resource_id',
        'action',
        'outcome',
        'before_state',
        'after_state',
        'payload',
        'metadata',
        'correlation_id',
        'ip_address',
        'user_agent',
        'occurred_at',
    ];

    protected $casts = [
        'before_state' => 'array',
        'after_state'  => 'array',
        'payload'      => 'array',
        'metadata'     => 'array',
        'occurred_at'  => 'datetime',
    ];

    public function save(array $options = []): bool
    {
        throw new \LogicException('Audit events are immutable. Use AuditEventModel::insert() for new records.');
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        throw new \LogicException('Audit events are immutable.');
    }

    public function delete(): bool|null
    {
        throw new \LogicException('Audit events are immutable and cannot be deleted.');
    }
}
