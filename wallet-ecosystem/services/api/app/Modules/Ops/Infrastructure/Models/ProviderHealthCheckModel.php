<?php

declare(strict_types=1);

namespace App\Modules\Ops\Infrastructure\Models;

use App\Modules\Ops\Domain\Enums\HealthStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $provider_type
 * @property HealthStatus $status
 * @property int|null $response_time_ms
 * @property string|null $error_message
 * @property Carbon $checked_at
 */
final class ProviderHealthCheckModel extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $connection = 'platform';

    protected $table = 'ops.provider_health_checks';

    public $timestamps = false;

    protected $fillable = [
        'integration_id', 'id', 'provider_type', 'status', 'response_time_ms', 'error_message', 'checked_at', 'created_at',
    ];

    protected $casts = [
        'status' => HealthStatus::class,
        'checked_at' => 'datetime',
        'created_at' => 'datetime',
    ];
}
