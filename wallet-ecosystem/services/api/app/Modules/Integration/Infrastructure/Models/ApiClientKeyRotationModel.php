<?php

declare(strict_types=1);

namespace App\Modules\Integration\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string      $id
 * @property string      $api_client_id
 * @property string      $old_key_prefix
 * @property string|null $rotated_by_user_id
 * @property \Carbon\CarbonInterface $rotated_at
 * @property string|null $reason
 */
final class ApiClientKeyRotationModel extends Model
{
    protected $connection = 'platform';

    protected $table = 'integration.api_client_key_rotations';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'rotated_at' => 'datetime',
    ];

    public function apiClient(): BelongsTo
    {
        return $this->belongsTo(ApiClientModel::class, 'api_client_id');
    }
}
