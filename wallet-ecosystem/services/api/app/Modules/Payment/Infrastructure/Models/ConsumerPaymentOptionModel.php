<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string       $id
 * @property string       $account_id
 * @property string       $display_label
 * @property string       $payment_method
 * @property int          $display_order
 * @property array<string> $supported_purpose_types
 * @property array<string> $country_restrictions
 * @property bool         $is_active
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 */
final class ConsumerPaymentOptionModel extends Model
{
    protected $connection = 'payment';
    protected $table      = 'payment.consumer_payment_options';

    public $incrementing = false;
    protected $keyType   = 'string';

    protected $fillable = [
        'id',
        'account_id',
        'display_label',
        'payment_method',
        'display_order',
        'supported_purpose_types',
        'country_restrictions',
        'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'supported_purpose_types' => 'array',
            'country_restrictions'    => 'array',
            'is_active'               => 'boolean',
            'display_order'           => 'integer',
            'created_at'              => 'immutable_datetime',
            'updated_at'              => 'immutable_datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(PaymentProviderAccountModel::class, 'account_id');
    }

    /**
     * Scope for active options that match the given context.
     *
     * @param Builder<ConsumerPaymentOptionModel> $query
     * @param array{purpose_type?: string, country_code?: string} $context
     * @return Builder<ConsumerPaymentOptionModel>
     */
    public function scopeActiveForContext(Builder $query, array $context): Builder
    {
        $query->where('is_active', true)
            ->join('payment.payment_provider_accounts as ppa', 'ppa.id', '=', 'payment.consumer_payment_options.account_id')
            ->where('ppa.account_lifecycle_status', 'active');

        if (!empty($context['purpose_type'])) {
            $purposeType = $context['purpose_type'];
            $query->where(static function (Builder $q) use ($purposeType): void {
                $q->whereJsonContains('payment.consumer_payment_options.supported_purpose_types', $purposeType)
                  ->orWhereJsonLength('payment.consumer_payment_options.supported_purpose_types', 0);
            });
        }

        if (!empty($context['country_code'])) {
            $countryCode = $context['country_code'];
            $query->where(static function (Builder $q) use ($countryCode): void {
                $q->whereJsonContains('payment.consumer_payment_options.country_restrictions', $countryCode)
                  ->orWhereJsonLength('payment.consumer_payment_options.country_restrictions', 0);
            });
        }

        return $query->orderBy('display_order');
    }
}
