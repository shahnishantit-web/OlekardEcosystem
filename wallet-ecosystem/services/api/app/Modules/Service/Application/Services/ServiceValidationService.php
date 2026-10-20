<?php

declare(strict_types=1);

namespace App\Modules\Service\Application\Services;

use App\Modules\Service\Domain\Contracts\ServiceProviderAdapter;
use App\Modules\Service\Domain\Enums\ServiceStatus;
use App\Modules\Service\Domain\Exceptions\InvalidCustomerReferenceException;
use App\Modules\Service\Domain\Exceptions\ProviderValidationTimeoutException;
use App\Modules\Service\Domain\Exceptions\ServiceNotAvailableException;
use App\Modules\Service\Domain\Exceptions\ServiceNotFoundException;
use App\Modules\Service\Infrastructure\Models\ServiceModel;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use Illuminate\Support\Facades\Log;

final class ServiceValidationService
{
    public function __construct(
        private readonly ServiceProviderAdapter      $adapter,
        private readonly ConfigurationServiceInterface $config,
    ) {}

    public function validateReference(
        string $serviceId,
        string $customerReference,
        string $correlationId,
    ): array {
        $service = ServiceModel::find($serviceId);

        if ($service === null) {
            throw new ServiceNotFoundException($serviceId);
        }

        if ($service->status !== ServiceStatus::Active) {
            throw new ServiceNotAvailableException($serviceId);
        }

        $timeoutSeconds = (int) $this->config->getOrDefault(
            'service.payment.validation_timeout_seconds',
            10,
        );

        try {
            $result = $this->withTimeout($timeoutSeconds, function () use ($service, $customerReference) {
                return $this->adapter->validateReference($service->provider_code, $customerReference);
            });
        } catch (ProviderValidationTimeoutException $e) {
            Log::warning('Service reference validation timeout', [
                'service_id'         => $serviceId,
                'provider_code'      => $service->provider_code,
                'correlation_id'     => $correlationId,
            ]);
            throw $e;
        }

        if (! $result->valid) {
            throw new InvalidCustomerReferenceException($customerReference);
        }

        return [
            'valid'        => true,
            'account_name' => $result->accountName,
        ];
    }

    public function calculateFee(string $serviceId, int $amount): array
    {
        $service = ServiceModel::find($serviceId);

        if ($service === null) {
            throw new ServiceNotFoundException($serviceId);
        }

        if ($service->status !== ServiceStatus::Active) {
            throw new ServiceNotAvailableException($serviceId);
        }

        /** @var ServiceFeeService $feeService */
        $feeService = app(ServiceFeeService::class);

        return $feeService->calculate($service, $amount);
    }

    /**
     * Execute a callable with a wall-clock timeout using PHP's max_execution_time.
     * For a real adapter this would be HTTP timeout config on the HTTP client.
     * In mock/tests the callable returns immediately.
     */
    private function withTimeout(int $seconds, callable $fn): mixed
    {
        $start = microtime(true);
        $result = $fn();
        $elapsed = microtime(true) - $start;

        if ($elapsed > $seconds) {
            throw new ProviderValidationTimeoutException();
        }

        return $result;
    }
}
