<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string       $id
 * @property string       $profile_identifier
 * @property string       $version
 * @property string       $display_name
 * @property string|null  $description
 * @property array<string> $supported_auth_mechanisms
 * @property array<string> $supported_capabilities
 * @property array<string, mixed> $config_schema
 * @property bool         $is_active
 * @property string|null  $notes
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 */
final class IntegrationProfileModel extends Model
{
    protected $connection = 'payment';
    protected $table      = 'payment.integration_profiles';

    public $incrementing = false;
    protected $keyType   = 'string';

    protected $fillable = [
        'id',
        'profile_identifier',
        'version',
        'display_name',
        'description',
        'supported_auth_mechanisms',
        'supported_capabilities',
        'config_schema',
        'is_active',
        'notes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'supported_auth_mechanisms' => 'array',
            'supported_capabilities'    => 'array',
            'config_schema'             => 'array',
            'is_active'                 => 'boolean',
            'created_at'                => 'immutable_datetime',
            'updated_at'                => 'immutable_datetime',
        ];
    }
}
