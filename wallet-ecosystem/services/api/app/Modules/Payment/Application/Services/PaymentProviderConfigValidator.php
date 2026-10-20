<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Services;

use App\Modules\Payment\Domain\Contracts\PaymentProviderRepositoryInterface;
use Illuminate\Support\Facades\App;
use Illuminate\Validation\ValidationException;

final class PaymentProviderConfigValidator
{
    public function __construct(
        private readonly PaymentProviderRepositoryInterface $providerRepository,
    ) {
    }

    /**
     * Validate that a provider account is fully configured before enabling.
     *
     * @throws ValidationException with field-level messages on failure
     */
    public function validateForEnable(string $providerAccountId): void
    {
        $account = $this->providerRepository->findProviderAccount($providerAccountId);

        if ($account === null) {
            throw ValidationException::withMessages([
                'provider_account_id' => ["Provider account [{$providerAccountId}] not found."],
            ]);
        }

        $errors = [];

        if (empty($account->merchant_account_reference)) {
            $errors['merchant_account_reference'] = ['Merchant account reference is required.'];
        }

        if (empty($account->supported_currencies)) {
            $errors['supported_currencies'] = ['At least one supported currency is required.'];
        }

        if ($account->min_amount_minor <= 0) {
            $errors['min_amount_minor'] = ['Minimum amount must be a positive integer.'];
        }

        if ($account->max_amount_minor <= $account->min_amount_minor) {
            $errors['max_amount_minor'] = ['Maximum amount must be greater than minimum amount.'];
        }

        $provider = $account->provider;
        if ($provider !== null) {
            // Verify the adapter identifier is registered in the container
            $adapterClass = 'payment.adapter.'.$provider->adapter_identifier;
            if (!App::bound($adapterClass)) {
                $errors['adapter_identifier'] = [
                    "Adapter [{$provider->adapter_identifier}] is not registered in the service container. "
                    . "Bind it in PaymentServiceProvider before enabling.",
                ];
            }
        }

        if (!empty($errors)) {
            throw ValidationException::withMessages($errors);
        }
    }
}
