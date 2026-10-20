<?php

declare(strict_types=1);

namespace App\Modules\Service\Application\Services;

use App\Modules\Service\Domain\Enums\ServiceStatus;
use App\Modules\Service\Domain\Events\ServiceCreatedEvent;
use App\Modules\Service\Domain\Events\ServiceDeactivatedEvent;
use App\Modules\Service\Domain\Events\ServiceUpdatedEvent;
use App\Modules\Service\Domain\Exceptions\ServiceNotFoundException;
use App\Modules\Service\Infrastructure\Models\ServiceModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ServiceCatalogService
{
    public function __construct(
        private readonly AuditServiceInterface   $audit,
        private readonly EventPublisherInterface $events,
    ) {}

    public function create(array $data, string $actorId, string $correlationId): ServiceModel
    {
        return DB::connection('platform')->transaction(function () use ($data, $actorId, $correlationId) {
            $service = ServiceModel::create([
                'id'               => Str::orderedUuid()->toString(),
                'provider_code'    => $data['provider_code'],
                'name'             => $data['name'],
                'description'      => $data['description'] ?? null,
                'category'         => $data['category'],
                'status'           => ServiceStatus::Active->value,
                'currency_code'    => $data['currency_code'] ?? 'BWP',
                'min_amount'       => $data['min_amount'],
                'max_amount'       => $data['max_amount'],
                'fee_type'         => $data['fee_type'],
                'fee_value'        => $data['fee_value'],
                'required_fields'  => $data['required_fields'] ?? [],
                'created_by'       => $actorId,
            ]);

            $this->audit->record(new AuditEventData(
                action:        'service.catalog.created',
                actorType:     'admin',
                resourceType:  'service',
                correlationId: $correlationId,
                outcome:       'success',
                actorId:       $actorId,
                resourceId:    $service->id,
                afterState:    $service->toArray(),
            ));

            $this->events->publish(new ServiceCreatedEvent(
                serviceId:      $service->id,
                name:           $service->name,
                providerCode:   $service->provider_code,
                category:       $service->category,
                status:         $service->status->value,
                actorId:        $actorId,
                occurredAt:     now()->toIso8601String(),
                correlationId:  $correlationId,
            ));

            return $service;
        });
    }

    public function update(string $id, array $data, string $actorId, string $correlationId): ServiceModel
    {
        return DB::connection('platform')->transaction(function () use ($id, $data, $actorId, $correlationId) {
            $service = $this->findById($id);
            $beforeState = $service->toArray();

            $fillable = array_filter([
                'name'            => $data['name'] ?? null,
                'description'     => $data['description'] ?? null,
                'category'        => $data['category'] ?? null,
                'currency_code'   => $data['currency_code'] ?? null,
                'min_amount'      => $data['min_amount'] ?? null,
                'max_amount'      => $data['max_amount'] ?? null,
                'fee_type'        => $data['fee_type'] ?? null,
                'fee_value'       => isset($data['fee_value']) ? $data['fee_value'] : null,
                'required_fields' => $data['required_fields'] ?? null,
            ], fn ($v) => $v !== null);

            $service->fill($fillable)->save();

            $changedFields = array_keys($fillable);

            $this->audit->record(new AuditEventData(
                action:        'service.catalog.updated',
                actorType:     'admin',
                resourceType:  'service',
                correlationId: $correlationId,
                outcome:       'success',
                actorId:       $actorId,
                resourceId:    $service->id,
                beforeState:   $beforeState,
                afterState:    $service->toArray(),
            ));

            $this->events->publish(new ServiceUpdatedEvent(
                serviceId:     $service->id,
                changedFields: $changedFields,
                actorId:       $actorId,
                occurredAt:    now()->toIso8601String(),
                correlationId: $correlationId,
            ));

            return $service;
        });
    }

    public function deactivate(string $id, string $actorId, string $correlationId): void
    {
        DB::connection('platform')->transaction(function () use ($id, $actorId, $correlationId) {
            $service = $this->findById($id);
            $beforeState = $service->toArray();

            $service->status = ServiceStatus::Inactive;
            $service->save();

            $this->audit->record(new AuditEventData(
                action:        'service.catalog.deactivated',
                actorType:     'admin',
                resourceType:  'service',
                correlationId: $correlationId,
                outcome:       'success',
                actorId:       $actorId,
                resourceId:    $service->id,
                beforeState:   $beforeState,
                afterState:    $service->toArray(),
            ));

            $this->events->publish(new ServiceDeactivatedEvent(
                serviceId:     $service->id,
                reason:        'admin_deactivated',
                actorId:       $actorId,
                occurredAt:    now()->toIso8601String(),
                correlationId: $correlationId,
            ));
        });
    }

    public function reactivate(string $id, string $actorId, string $correlationId): void
    {
        DB::connection('platform')->transaction(function () use ($id, $actorId, $correlationId) {
            $service = $this->findById($id);
            $beforeState = $service->toArray();

            $service->status = ServiceStatus::Active;
            $service->save();

            $this->audit->record(new AuditEventData(
                action:        'service.catalog.reactivated',
                actorType:     'admin',
                resourceType:  'service',
                correlationId: $correlationId,
                outcome:       'success',
                actorId:       $actorId,
                resourceId:    $service->id,
                beforeState:   $beforeState,
                afterState:    $service->toArray(),
            ));

            $this->events->publish(new ServiceUpdatedEvent(
                serviceId:     $service->id,
                changedFields: ['status'],
                actorId:       $actorId,
                occurredAt:    now()->toIso8601String(),
                correlationId: $correlationId,
            ));
        });
    }

    public function findById(string $id): ServiceModel
    {
        $service = ServiceModel::find($id);
        if ($service === null) {
            throw new ServiceNotFoundException($id);
        }
        return $service;
    }

    public function list(array $filters, int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        $query = ServiceModel::query();

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        return $query->orderByDesc('created_at')->paginate(perPage: $perPage, page: $page);
    }
}
