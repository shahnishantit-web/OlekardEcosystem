<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Application;

use App\Modules\Service\Application\Services\ServiceCatalogService;
use App\Modules\Service\Domain\Enums\ServiceFeeType;
use App\Modules\Service\Domain\Enums\ServiceStatus;
use App\Modules\Service\Domain\Events\ServiceCreatedEvent;
use App\Modules\Service\Domain\Events\ServiceDeactivatedEvent;
use App\Modules\Service\Domain\Events\ServiceUpdatedEvent;
use App\Modules\Service\Domain\Exceptions\ServiceNotFoundException;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

final class ServiceCatalogServiceTest extends TestCase
{
    use DatabaseTransactions;

    private ServiceCatalogService $service;
    private AuditServiceInterface $audit;
    private EventPublisherInterface $events;

    protected function setUp(): void
    {
        parent::setUp();
        $this->audit  = $this->createMock(AuditServiceInterface::class);
        $this->events = $this->createMock(EventPublisherInterface::class);
        $this->service = new ServiceCatalogService($this->audit, $this->events);
    }

    private function sampleServiceData(): array
    {
        return [
            'name'            => 'Botswana Power Corporation',
            'category'        => 'UTILITIES',
            'description'     => 'Electricity bill payments',
            'provider_code'   => 'BPC',
            'currency_code'   => 'BWP',
            'min_amount'      => 100,
            'max_amount'      => 1000000,
            'fee_type'        => 'flat',
            'fee_value'       => 200,
            'required_fields' => [
                ['name' => 'meter_number', 'label' => 'Meter Number', 'max_length' => 10],
            ],
        ];
    }

    public function test_create_service_returns_active_model(): void
    {
        $this->audit->expects($this->once())->method('record')
            ->with($this->isInstanceOf(AuditEventData::class));
        $this->events->expects($this->once())->method('publish')
            ->with($this->isInstanceOf(ServiceCreatedEvent::class));

        $service = $this->service->create($this->sampleServiceData(), '00000000-0000-0000-0000-000000000001', 'corr-1');

        $this->assertSame(ServiceStatus::Active, $service->status);
        $this->assertSame('BPC', $service->provider_code);
        $this->assertSame(ServiceFeeType::Flat, $service->fee_type);
        $this->assertSame(200, $service->fee_value);
        $this->assertNotNull($service->id);
    }

    public function test_update_service_fee(): void
    {
        $this->audit->expects($this->exactly(2))->method('record');
        $this->events->expects($this->exactly(2))->method('publish');

        $created = $this->service->create($this->sampleServiceData(), '00000000-0000-0000-0000-000000000001', 'corr-1');
        $updated = $this->service->update($created->id, ['fee_value' => 500], '00000000-0000-0000-0000-000000000001', 'corr-2');

        $this->assertSame(500, $updated->fee_value);
        $this->assertSame($created->id, $updated->id);
    }

    public function test_deactivate_transitions_to_inactive(): void
    {
        $this->audit->expects($this->exactly(2))->method('record');
        $this->events->expects($this->atLeast(2))->method('publish');

        $created = $this->service->create($this->sampleServiceData(), '00000000-0000-0000-0000-000000000001', 'corr-1');
        $this->service->deactivate($created->id, '00000000-0000-0000-0000-000000000001', 'corr-2');

        $refreshed = $this->service->findById($created->id);
        $this->assertSame(ServiceStatus::Inactive, $refreshed->status);
    }

    public function test_reactivate_transitions_back_to_active(): void
    {
        $this->audit->method('record');
        $this->events->method('publish');

        $created = $this->service->create($this->sampleServiceData(), '00000000-0000-0000-0000-000000000001', 'corr-1');
        $this->service->deactivate($created->id, '00000000-0000-0000-0000-000000000001', 'corr-2');
        $this->service->reactivate($created->id, '00000000-0000-0000-0000-000000000001', 'corr-3');

        $refreshed = $this->service->findById($created->id);
        $this->assertSame(ServiceStatus::Active, $refreshed->status);
    }

    public function test_deactivated_service_still_findable_by_id(): void
    {
        $this->audit->method('record');
        $this->events->method('publish');

        $created = $this->service->create($this->sampleServiceData(), '00000000-0000-0000-0000-000000000001', 'corr-1');
        $this->service->deactivate($created->id, '00000000-0000-0000-0000-000000000001', 'corr-2');

        $found = $this->service->findById($created->id);
        $this->assertSame(ServiceStatus::Inactive, $found->status);
    }

    public function test_find_by_id_throws_for_missing_service(): void
    {
        $this->expectException(ServiceNotFoundException::class);
        $this->service->findById('00000000-0000-0000-0000-000000000000');
    }

    public function test_list_returns_paginated_results(): void
    {
        $this->audit->method('record');
        $this->events->method('publish');

        $this->service->create($this->sampleServiceData(), '00000000-0000-0000-0000-000000000001', 'corr-1');
        $this->service->create(
            array_merge($this->sampleServiceData(), ['name' => 'Water Service', 'provider_code' => 'WATER']),
            '00000000-0000-0000-0000-000000000001', 'corr-2'
        );

        $paginator = $this->service->list([], 1, 20);

        $this->assertGreaterThanOrEqual(2, $paginator->total());
    }

    public function test_update_emits_updated_event_with_changed_fields(): void
    {
        $this->audit->method('record');

        $updatedEvent = null;
        $this->events->method('publish')
            ->willReturnCallback(function ($event) use (&$updatedEvent) {
                if ($event instanceof ServiceUpdatedEvent) {
                    $updatedEvent = $event;
                }
            });

        $created = $this->service->create($this->sampleServiceData(), '00000000-0000-0000-0000-000000000001', 'corr-1');
        $this->service->update($created->id, ['fee_value' => 999, 'description' => 'Updated'], '00000000-0000-0000-0000-000000000001', 'corr-2');

        $this->assertNotNull($updatedEvent);
        $this->assertContains('fee_value', $updatedEvent->changedFields);
    }
}
