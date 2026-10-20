<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Application;

use App\Modules\Service\Application\Services\ServiceValidationService;
use App\Modules\Service\Domain\Contracts\ServiceProviderAdapter;
use App\Modules\Service\Domain\DTO\ReferenceValidationResult;
use App\Modules\Service\Domain\Exceptions\InvalidCustomerReferenceException;
use App\Modules\Service\Domain\Exceptions\ServiceNotAvailableException;
use App\Modules\Service\Domain\Exceptions\ServiceNotFoundException;
use App\Modules\Service\Infrastructure\Models\ServiceModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Service\Application\Services\ServiceCatalogService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

final class ServiceValidationServiceTest extends TestCase
{
    use DatabaseTransactions;

    private ConfigurationServiceInterface $config;
    private ServiceProviderAdapter $adapter;
    private ServiceValidationService $validationService;
    private ServiceCatalogService $catalog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config = $this->createMock(ConfigurationServiceInterface::class);
        $this->config->method('getOrDefault')->willReturn(10);

        $this->adapter = $this->createMock(ServiceProviderAdapter::class);

        $this->validationService = new ServiceValidationService($this->adapter, $this->config);

        $audit  = $this->createMock(AuditServiceInterface::class);
        $events = $this->createMock(EventPublisherInterface::class);
        $audit->method('record');
        $events->method('publish');
        $this->catalog = new ServiceCatalogService($audit, $events);
    }

    private function createService(): ServiceModel
    {
        return $this->catalog->create([
            'name'            => 'BPC Test',
            'category'        => 'UTILITIES',
            'provider_code'   => 'BPC_TEST_'.uniqid(),
            'currency_code'   => 'BWP',
            'min_amount'      => 100,
            'max_amount'      => 1000000,
            'fee_type'        => 'flat',
            'fee_value'       => 200,
            'required_fields' => [['name' => 'meter', 'label' => 'Meter Number']],
        ], '00000000-0000-0000-0000-000000000001', 'corr-1');
    }

    public function test_valid_reference_returns_account_name(): void
    {
        $service = $this->createService();

        $this->adapter->method('validateReference')
            ->willReturn(new ReferenceValidationResult(valid: true, accountName: 'John Doe'));

        $result = $this->validationService->validateReference(
            $service->id, '1234567890', 'corr-1'
        );

        $this->assertTrue($result['valid']);
        $this->assertSame('John Doe', $result['account_name']);
    }

    public function test_invalid_reference_throws_exception(): void
    {
        $service = $this->createService();

        $this->adapter->method('validateReference')
            ->willReturn(new ReferenceValidationResult(valid: false, accountName: ''));

        $this->expectException(InvalidCustomerReferenceException::class);
        $this->validationService->validateReference($service->id, 'INVALID123', 'corr-1');
    }

    public function test_inactive_service_throws_not_available(): void
    {
        $service = $this->createService();

        $audit  = $this->createMock(AuditServiceInterface::class);
        $events = $this->createMock(EventPublisherInterface::class);
        $audit->method('record');
        $events->method('publish');
        $catalog = new ServiceCatalogService($audit, $events);
        $catalog->deactivate($service->id, '00000000-0000-0000-0000-000000000001', 'corr-2');

        $this->expectException(ServiceNotAvailableException::class);
        $this->validationService->validateReference($service->id, '1234567890', 'corr-1');
    }

    public function test_unknown_service_throws_not_found(): void
    {
        $this->expectException(ServiceNotFoundException::class);
        $this->validationService->validateReference('00000000-0000-0000-0000-000000000000', 'ref', 'corr-1');
    }
}
