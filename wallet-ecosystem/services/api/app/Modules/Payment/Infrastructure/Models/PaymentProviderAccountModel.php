<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PaymentProviderAccountModel extends Model
{
    protected $connection = 'payment';
    protected $table      = 'payment.payment_provider_accounts';

    public $incrementing = false;
    protected $keyType   = 'string';

    protected $fillable = [
        'id',
        'provider_id',
        'merchant_account_reference',
        'supported_currencies',
        'supported_payment_methods',
        'min_amount_minor',
        'max_amount_minor',
        'enabled_organisations',
        'enabled_modules',
        'country_restrictions',
        'callback_url',
        'callback_secret_reference',
        'capability_flags',
        'fee_rule_ids',
        'daily_limit_minor',
        'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'supported_currencies'    => 'array',
            'supported_payment_methods' => 'array',
            'enabled_organisations'   => 'array',
            'enabled_modules'         => 'array',
            'country_restrictions'    => 'array',
            'capability_flags'        => 'array',
            'fee_rule_ids'            => 'array',
            'min_amount_minor'        => 'integer',
            'max_amount_minor'        => 'integer',
            'daily_limit_minor'       => 'integer',
            'is_active'               => 'boolean',
            'created_at'              => 'immutable_datetime',
            'updated_at'              => 'immutable_datetime',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(PaymentProviderModel::class, 'provider_id');
    }
}
