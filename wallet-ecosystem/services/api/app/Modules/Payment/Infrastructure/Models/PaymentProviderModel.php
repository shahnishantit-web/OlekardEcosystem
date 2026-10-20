<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class PaymentProviderModel extends Model
{
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
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_enabled'   => 'boolean',
            'is_suspended' => 'boolean',
            'is_default'   => 'boolean',
            'sort_order'   => 'integer',
            'created_at'   => 'immutable_datetime',
            'updated_at'   => 'immutable_datetime',
        ];
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(PaymentProviderAccountModel::class, 'provider_id');
    }

    public function isMock(): bool
    {
        return $this->environment === 'mock';
    }
}
