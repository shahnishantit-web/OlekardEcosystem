<?php

declare(strict_types=1);

namespace App\Modules\Integration\Infrastructure\Models;

use App\Modules\Integration\Domain\Enums\ApiClientStatus;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $name
 * @property string $api_key
 * @property string $secret_hash
 * @property ApiClientStatus $status
 * @property array $permissions
 * @property string|null $integration_id
 * @property array<int, string>|null $ip_whitelist
 * @property Carbon|null $revoked_at
 * @property int $rate_limit_per_minute
 * @property string|null $last_used_at
 * @property string|null $created_by_user_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class ApiClientModel extends Model
{
    protected $connection = 'platform';

    protected $table = 'integration.api_clients';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['secret_hash'];

    protected $casts = [
        'ip_whitelist' => 'array', 'revoked_at' => 'datetime',
        'status' => ApiClientStatus::class,
        'permissions' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function rotations(): HasMany
    {
        return $this->hasMany(ApiClientKeyRotationModel::class, 'api_client_id');
    }
}
