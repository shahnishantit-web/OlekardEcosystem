<?php

declare(strict_types=1);

namespace App\Modules\Integration\Infrastructure\Models;

use App\Modules\Integration\Domain\Enums\IntegrationProviderType;
use App\Modules\Integration\Domain\Enums\IntegrationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string                 $id
 * @property IntegrationProviderType $provider_type
 * @property string                 $provider_slug
 * @property string                 $display_name
 * @property string                 $credentials_reference
 * @property string|null            $endpoint_override
 * @property IntegrationStatus      $status
 * @property bool                   $is_default
 * @property array|null             $configuration
 * @property string|null            $created_by_user_id
 * @property \Carbon\Carbon         $created_at
 * @property \Carbon\Carbon         $updated_at
 */
final class IntegrationModel extends Model
{
    protected $connection = 'platform';

    protected $table = 'integration.integrations';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'provider_type' => IntegrationProviderType::class,
        'status'        => IntegrationStatus::class,
        'is_default'    => 'boolean',
        'configuration' => 'array',
        'created_at'    => 'datetime',
        'updated_at'    => 'datetime',
    ];

    public function callLogs(): HasMany
    {
        return $this->hasMany(ProviderCallLogModel::class, 'integration_id');
    }
}
