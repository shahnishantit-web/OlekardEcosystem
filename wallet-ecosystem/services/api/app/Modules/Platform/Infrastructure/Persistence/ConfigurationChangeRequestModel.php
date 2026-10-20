<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Persistence;

use App\Modules\Platform\Domain\Enums\ChangeRequestStatus;
use App\Modules\Platform\Domain\Enums\ConfigurationDomain;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $config_key
 * @property ConfigurationDomain $domain
 * @property array<string,mixed> $proposed_value
 * @property array<string,mixed>|null $previous_value
 * @property string $maker_id
 * @property ChangeRequestStatus $status
 * @property string|null $rejection_reason
 * @property string $idempotency_key
 * @property string $correlation_id
 * @property Carbon $submitted_at
 * @property Carbon|null $resolved_at
 */
final class ConfigurationChangeRequestModel extends Model
{
    protected $connection = 'platform';

    protected $table = 'platform.configuration_change_requests';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'config_key',
        'domain',
        'proposed_value',
        'previous_value',
        'maker_id',
        'status',
        'rejection_reason',
        'idempotency_key',
        'correlation_id',
        'submitted_at',
        'resolved_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'proposed_value' => 'array',
        'previous_value' => 'array',
        'status' => ChangeRequestStatus::class,
        'domain' => ConfigurationDomain::class,
        'submitted_at' => 'datetime',
        'resolved_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** @return HasMany<ConfigurationApprovalModel, $this> */
    public function approvals(): HasMany
    {
        return $this->hasMany(ConfigurationApprovalModel::class, 'change_request_id');
    }

    /** @return HasMany<ConfigurationVersionModel, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(ConfigurationVersionModel::class, 'change_request_id');
    }
}
