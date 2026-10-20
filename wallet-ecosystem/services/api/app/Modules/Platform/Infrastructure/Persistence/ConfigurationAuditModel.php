<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;

final class ConfigurationAuditModel extends Model
{
    protected $connection = 'platform';

    protected $table = 'platform.configuration_audit';

    public $timestamps = false;

    protected $fillable = [
        'id',
        'configuration_value_id',
        'action',
        'actor_id',
        'correlation_id',
        'old_value',
        'new_value',
        'occurred_at',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
    ];

    public function save(array $options = []): bool
    {
        throw new \LogicException('Configuration audit records are immutable.');
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        throw new \LogicException('Configuration audit records are immutable.');
    }

    public function delete(): bool|null
    {
        throw new \LogicException('Configuration audit records are immutable.');
    }
}
