<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Models;

use App\Modules\Payment\Domain\ValueObjects\SecretReference;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string       $id
 * @property string       $provider_id
 * @property string       $merchant_account_reference
 * @property array<string> $supported_currencies
 * @property array<string> $supported_payment_methods
 * @property int          $min_amount_minor
 * @property int          $max_amount_minor
 * @property array<string> $enabled_organisations
 * @property array<string> $enabled_modules
 * @property array<string> $country_restrictions
 * @property string|null  $callback_url
 * @property string|null  $callback_secret_reference
 * @property array<string, mixed> $capability_flags
 * @property array<string> $fee_rule_ids
 * @property int|null     $daily_limit_minor
 * @property bool         $is_active
 * @property int          $config_version
 * @property array<string> $enabled_purpose_types
 * @property string|null  $display_description
 * @property string|null  $display_icon_key
 * @property string|null  $display_badge
 * @property string       $account_lifecycle_status
 * @property string|null  $submitted_by_actor_id
 * @property string|null  $approved_by_actor_id
 * @property \Carbon\CarbonImmutable|null $approved_at
 * @property \Carbon\CarbonImmutable|null $activated_at
 * @property \Carbon\CarbonImmutable|null $suspended_at
 * @property \Carbon\CarbonImmutable|null $retired_at
 * @property string|null  $rejection_reason
 * @property string|null  $active_config_version_id
 * @property string|null  $display_label
 * @property int          $display_sort_order
 * @property Carbon|null  $created_at
 * @property Carbon|null  $updated_at
 */
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
        'config_version',
        'enabled_purpose_types',
        'display_description',
        'display_icon_key',
        'display_badge',
        'account_lifecycle_status',
        'submitted_by_actor_id',
        'approved_by_actor_id',
        'approved_at',
        'activated_at',
        'suspended_at',
        'retired_at',
        'rejection_reason',
        'active_config_version_id',
        'display_label',
        'display_sort_order',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'supported_currencies'      => 'array',
            'supported_payment_methods' => 'array',
            'enabled_organisations'     => 'array',
            'enabled_modules'           => 'array',
            'country_restrictions'      => 'array',
            'capability_flags'          => 'array',
            'fee_rule_ids'              => 'array',
            'min_amount_minor'          => 'integer',
            'max_amount_minor'          => 'integer',
            'daily_limit_minor'         => 'integer',
            'is_active'                 => 'boolean',
            'config_version'            => 'integer',
            'enabled_purpose_types'     => 'array',
            'display_sort_order'        => 'integer',
            'approved_at'               => 'immutable_datetime',
            'activated_at'              => 'immutable_datetime',
            'suspended_at'              => 'immutable_datetime',
            'retired_at'                => 'immutable_datetime',
            'created_at'                => 'immutable_datetime',
            'updated_at'                => 'immutable_datetime',
        ];
    }

    /**
     * T077: Returns false when the account lifecycle prohibits new payment attempts.
     * Suspended accounts continue processing existing attempts (callbacks/refunds) but reject new ones.
     * Retired accounts reject all new attempts unconditionally.
     */
    public function isEligibleForNewAttempts(): bool
    {
        return !in_array(
            $this->account_lifecycle_status,
            ['suspended', 'retired'],
            true
        );
    }

    /**
     * T056: FR-014 — callback_secret_reference is always returned as SecretReference VO.
     * toArray() / toJson() will call jsonSerialize() → ['key' => ..., 'masked' => '****'].
     */
    protected function callbackSecretReference(): Attribute
    {
        return Attribute::make(
            get: static fn (?string $value): ?SecretReference => $value !== null
                ? new SecretReference($value)
                : null,
            set: static fn (string|SecretReference|null $value): ?string => match (true) {
                $value instanceof SecretReference => $value->keyName,
                is_string($value)                 => $value,
                default                           => null,
            },
        );
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(PaymentProviderModel::class, 'provider_id');
    }
}
