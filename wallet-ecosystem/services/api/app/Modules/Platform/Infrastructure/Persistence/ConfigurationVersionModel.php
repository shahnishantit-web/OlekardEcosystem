<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Persistence;

use App\Modules\Platform\Domain\Enums\ConfigurationDomain;
use App\Modules\Platform\Domain\Enums\VersionEventType;
use Illuminate\Database\Eloquent\Model;

final class ConfigurationVersionModel extends Model
{
    protected $connection = 'platform';

    protected $table = 'platform.configuration_versions';

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'config_key',
        'domain',
        'event_type',
        'value',
        'actor_id',
        'change_request_id',
        'notes',
        'occurred_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'event_type'  => VersionEventType::class,
        'domain'      => ConfigurationDomain::class,
        'value'       => 'array',
        'occurred_at' => 'datetime',
        'created_at'  => 'datetime',
    ];

    public function save(array $options = []): bool
    {
        throw new \LogicException('Configuration version records are immutable.');
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        throw new \LogicException('Configuration version records are immutable.');
    }

    public function delete(): bool|null
    {
        throw new \LogicException('Configuration version records are immutable.');
    }
}
