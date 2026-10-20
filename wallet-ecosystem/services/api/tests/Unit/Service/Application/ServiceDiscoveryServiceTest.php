<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Application;

use App\Modules\Service\Application\Services\ServiceCatalogService;
use App\Modules\Service\Application\Services\ServiceDiscoveryService;
use App\Modules\Service\Domain\Enums\ServiceStatus;
use App\Modules\Service\Domain\Exceptions\ServiceNotFoundException;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

final class ServiceDiscoveryServiceTest extends TestCase
{
    use DatabaseTransactions;

    private ServiceDiscoveryService $discovery;
    private ServiceCatalogService $catalog;

    protected function setUp(): void
    {
        parent::setUp();
        $audit  = $this->createMock(AuditServiceInterface::class);
        $events = $this->createMock(EventPublisherInterface::class);
        $audit->method('record');
        $events->method('publish');

        $this->catalog   = new ServiceCatalogService($audit, $events);
        $this->discovery = new ServiceDiscoveryService();
    }

    private function createActiveService(string $name = 'BPC Electricity', string $category = 'UTILITIES'): void
    {
        $this->catalog->create([
            'name'            => $name,
            'category'        => $category,
            'provider_code'   => strtoupper(str_replace(' ', '_', $name)),
            'currency_code'   => 'BWP',
            'min_amount'      => 100,
            'max_amount'      => 1000000,
            'fee_type'        => 'flat',
            'fee_value'       => 200,
            'required_fields' => [['name' => 'reference', 'label' => 'Reference']],
        ], '00000000-0000-0000-0000-000000000001', 'corr-1');
    }

    public function test_list_returns_only_active_services(): void
    {
        $this->createActiveService('Active Service A');

        $inactiveAudit  = $this->createMock(AuditServiceInterface::class);
        $inactiveEvents = $this->createMock(EventPublisherInterface::class);
        $inactiveAudit->method('record');
        $inactiveEvents->method('publish');
        $inactiveCatalog = new ServiceCatalogService($inactiveAudit, $inactiveEvents);

        $inactiveCatalog->create([
            'name'            => 'Inactive Service B',
            'category'        => 'UTILITIES',
            'provider_code'   => 'INACTIVE_B',
            'currency_code'   => 'BWP',
            'min_amount'      => 100,
            'max_amount'      => 1000000,
            'fee_type'        => 'flat',
            'fee_value'       => 200,
            'required_fields' => [['name' => 'reference', 'label' => 'Reference']],
        ], '00000000-0000-0000-0000-000000000001', 'corr-x');
        $created = $inactiveCatalog->findById(
            \App\Modules\Service\Infrastructure\Models\ServiceModel::where('provider_code', 'INACTIVE_B')->first()->id
        );
        $inactiveCatalog->deactivate($created->id, '00000000-0000-0000-0000-000000000001', 'corr-y');

        $paginator = $this->discovery->list([]);

        $names = $paginator->pluck('name')->toArray();
        $this->assertContains('Active Service A', $names);
        $this->assertNotContains('Inactive Service B', $names);
    }

    public function test_list_filters_by_category(): void
    {
        $this->createActiveService('Water Utility', 'WATER');
        $this->createActiveService('Gas Provider', 'GAS');

        $paginator = $this->discovery->list(['category' => 'WATER']);

        $categories = $paginator->pluck('category')->unique()->toArray();
        $this->assertSame(['WATER'], array_values($categories));
    }

    public function test_list_searches_by_name_substring(): void
    {
        $this->createActiveService('Botswana Power Corporation');
        $this->createActiveService('National Water');

        $paginator = $this->discovery->list(['search' => 'Botswana']);

        $names = $paginator->pluck('name')->toArray();
        $this->assertContains('Botswana Power Corporation', $names);
        $this->assertNotContains('National Water', $names);
    }

    public function test_show_returns_active_service(): void
    {
        $this->createActiveService('Find Me Service');
        $id = \App\Modules\Service\Infrastructure\Models\ServiceModel::where('name', 'Find Me Service')->first()->id;

        $found = $this->discovery->show($id);

        $this->assertSame('Find Me Service', $found->name);
        $this->assertSame(ServiceStatus::Active, $found->status);
    }

    public function test_show_throws_for_inactive_service(): void
    {
        $audit  = $this->createMock(AuditServiceInterface::class);
        $events = $this->createMock(EventPublisherInterface::class);
        $audit->method('record');
        $events->method('publish');
        $catalog = new ServiceCatalogService($audit, $events);

        $catalog->create([
            'name'            => 'Hidden Service',
            'category'        => 'UTILITIES',
            'provider_code'   => 'HIDDEN',
            'currency_code'   => 'BWP',
            'min_amount'      => 100,
            'max_amount'      => 1000000,
            'fee_type'        => 'flat',
            'fee_value'       => 0,
            'required_fields' => [['name' => 'reference', 'label' => 'Ref']],
        ], '00000000-0000-0000-0000-000000000001', 'corr-1');
        $id = \App\Modules\Service\Infrastructure\Models\ServiceModel::where('provider_code', 'HIDDEN')->first()->id;
        $catalog->deactivate($id, '00000000-0000-0000-0000-000000000001', 'corr-2');

        $this->expectException(ServiceNotFoundException::class);
        $this->discovery->show($id);
    }

    public function test_show_throws_for_nonexistent_service(): void
    {
        $this->expectException(ServiceNotFoundException::class);
        $this->discovery->show('00000000-0000-0000-0000-000000000000');
    }
}
