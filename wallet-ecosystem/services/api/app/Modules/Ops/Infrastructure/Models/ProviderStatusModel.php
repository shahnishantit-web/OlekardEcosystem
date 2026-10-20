<?php

declare(strict_types=1);

namespace App\Modules\Ops\Infrastructure\Models;

use App\Modules\Ops\Domain\Enums\ProviderStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string                          $id
 * @property string                          $provider_type
 * @property ProviderStatus                  $status
 * @property int                             $consecutive_failures
 * @property \Illuminate\Support\Carbon|null $last_checked_at
 * @property \Illuminate\Support\Carbon|null $degraded_since
 * @property \Illuminate\Support\Carbon|null $down_since
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
final class ProviderStatusModel extends Model
{
    public    $incrementing = false;
    protected $keyType      = 'string';
    protected $connection   = 'platform';
    protected $table        = 'ops.provider_statuses';

    protected $fillable = [
        'id', 'provider_type', 'status', 'consecutive_failures',
        'last_checked_at', 'degraded_since', 'down_since',
    ];

    protected $casts = [
        'status'             => ProviderStatus::class,
        'last_checked_at'    => 'datetime',
        'degraded_since'     => 'datetime',
        'down_since'         => 'datetime',
    ];
}
