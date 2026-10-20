<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Repositories;

use App\Modules\Payment\Domain\Contracts\PaymentProviderRepositoryInterface;
use App\Modules\Payment\Domain\ValueObjects\OptionsQuery;
use App\Modules\Payment\Infrastructure\Models\PaymentProviderAccountModel;
use App\Modules\Payment\Infrastructure\Models\PaymentProviderModel;

final class EloquentPaymentProviderRepository implements PaymentProviderRepositoryInterface
{
    public function findByCode(string $code): ?PaymentProviderModel
    {
        return PaymentProviderModel::query()
            ->where('code', $code)
            ->first();
    }

    /** @return PaymentProviderAccountModel[] */
    public function findActiveForContext(OptionsQuery $query): array
    {
        return PaymentProviderAccountModel::query()
            ->with('provider')
            ->whereHas('provider', static function ($q): void {
                $q->where('is_enabled', true)->where('is_suspended', false);
            })
            ->where('is_active', true)
            ->whereRaw("supported_currencies::jsonb @> ?::jsonb", [json_encode([$query->currencyCode])])
            ->where('min_amount_minor', '<=', $query->amountMinor)
            ->where('max_amount_minor', '>=', $query->amountMinor)
            ->where(function ($q) use ($query): void {
                // Empty enabled_organisations = all orgs allowed
                $q->whereRaw("jsonb_array_length(enabled_organisations) = 0")
                  ->orWhereRaw("enabled_organisations::jsonb @> ?::jsonb", [json_encode([$query->organisationId])]);
            })
            ->where(function ($q) use ($query): void {
                if ($query->moduleKey === '') {
                    $q->whereRaw("jsonb_array_length(enabled_modules) = 0");
                } else {
                    $q->whereRaw("jsonb_array_length(enabled_modules) = 0")
                      ->orWhereRaw("enabled_modules::jsonb @> ?::jsonb", [json_encode([$query->moduleKey])]);
                }
            })
            ->join('payment.payment_providers as p', 'p.id', '=', 'payment.payment_provider_accounts.provider_id')
            ->orderBy('p.sort_order')
            ->get()
            ->all();
    }

    public function findProviderAccount(string $providerAccountId): ?PaymentProviderAccountModel
    {
        return PaymentProviderAccountModel::query()
            ->with('provider')
            ->find($providerAccountId);
    }
}
