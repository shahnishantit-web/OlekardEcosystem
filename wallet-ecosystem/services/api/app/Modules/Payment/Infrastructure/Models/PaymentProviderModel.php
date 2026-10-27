<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string      $id
 * @property string      $code
 * @property string      $display_name
 * @property string      $adapter_identifier
 * @property string      $adapter_version
 * @property string      $environment
 * @property bool        $is_enabled
 * @property bool        $is_suspended
 * @property bool        $is_default
 * @property int         $sort_order
 * @property string      $bank_lifecycle_status
 * @property array<string> $supported_countries
 * @property string|null $organisation_ownership
 * @property string|null $description
 * @property string|null $logo_reference
 * @property int         $metadata_version
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 */
final class PaymentProviderModel extends Model
{
    protected static function booted(): void
    {
        static::saving(function (self $provider): void {
            if ($provider->is_enabled && ($provider->adapter_identifier === 'mock_bank' || $provider->environment === 'mock')) {
                \App\Modules\Payment\Infrastructure\Adapters\DevelopmentPaymentGuard::check();
            }
        });
    }

    protected $connection = 'payment';
    protected $table      = 'payment.payment_providers';

    public $incrementing = false;
    protected $keyType   = 'string';

    protected $fillable = [
        'id',
        'code',
        'display_name',
        'adapter_identifier',
        'adapter_version',
        'environment',
        'is_enabled',
        'is_suspended',
        'sort_order',
        'is_default',
        'bank_lifecycle_status',
        'supported_countries',
        'organisation_ownership',
        'description',
        'logo_reference',
        'metadata_version',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_enabled'          => 'boolean',
            'is_suspended'        => 'boolean',
            'is_default'          => 'boolean',
            'sort_order'          => 'integer',
            'supported_countries' => 'array',
            'metadata_version'    => 'integer',
            'created_at'          => 'immutable_datetime',
            'updated_at'          => 'immutable_datetime',
        ];
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(PaymentProviderAccountModel::class, 'provider_id');
    }

    public function isMock(): bool
    {
        return $this->environment === 'mock' || $this->adapter_identifier === 'mock_bank';
    }
}
