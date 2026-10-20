<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application\Services;

use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationVersionRepositoryInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Support\Facades\DB;

final class ConfigurationAdminService
{
    public function __construct(
        private readonly AuditServiceInterface $auditService,
        private readonly ConfigurationVersionRepositoryInterface $versionRepository,
    ) {}

    /** @param array<string, mixed> $filters */
    public function listKeys(
        array $filters,
        int $perPage,
        ?string $actorId,
        string $correlationId,
    ): array {
        $includeInactive = (bool) ($filters['include_inactive'] ?? false);

        // When include_inactive=false (default), only show keys with an active value.
        // When include_inactive=true, show all keys; for those without an active value,
        // surface the most recent archived value and its deactivated_at timestamp.
        $activeJoin = DB::raw(
            "( SELECT DISTINCT ON (config_key) id, config_key, config_value, updated_at
               FROM platform.configuration_values
               WHERE status = 'active'
               ORDER BY config_key, updated_at DESC ) AS cv"
        );

        $query = DB::connection('platform')
            ->table('platform.configurations as c')
            ->leftJoin($activeJoin, 'c.config_key', '=', 'cv.config_key')
            ->leftJoin(
                DB::raw("(SELECT config_key, id as pending_change_request_id FROM platform.configuration_change_requests WHERE status = 'pending_approval') AS cr"),
                'c.config_key',
                '=',
                'cr.config_key',
            )
            ->select([
                'c.config_key',
                'c.domain',
                'cv.config_value as current_value',
                'cv.updated_at',
                DB::raw("CASE WHEN cr.pending_change_request_id IS NOT NULL THEN TRUE ELSE FALSE END AS has_pending_change"),
                'cr.pending_change_request_id',
            ]);

        if ($includeInactive) {
            // Also join to the most recent archived value (deactivated_at) for keys with no active value
            $archivedJoin = DB::raw(
                "( SELECT DISTINCT ON (config_key) config_key, updated_at AS deactivated_at
                   FROM platform.configuration_values
                   WHERE status = 'archived'
                   ORDER BY config_key, updated_at DESC ) AS av"
            );
            $query->leftJoin($archivedJoin, 'c.config_key', '=', 'av.config_key')
                  ->addSelect(DB::raw('COALESCE(av.deactivated_at, NULL) AS deactivated_at'));
        } else {
            // Without include_inactive, only show keys that currently have an active value
            $query->whereNotNull('cv.config_key');
        }

        if (isset($filters['domain'])) {
            $query->where('c.domain', $filters['domain']);
        }

        if (isset($filters['search'])) {
            $query->where('c.config_key', 'like', '%' . $filters['search'] . '%');
        }

        $result = $query->orderBy('c.config_key')->paginate($perPage);

        $this->auditService->record(new AuditEventData(
            action:        'configuration.key.list',
            actorType:     'USER',
            resourceType:  'ConfigurationKey',
            correlationId: $correlationId,
            outcome:       'success',
            actorId:       $actorId,
        ));

        return $result->toArray();
    }

    public function getKeyDetail(
        string $key,
        ?string $actorId,
        string $correlationId,
    ): ?array {
        $config = DB::connection('platform')
            ->table('platform.configurations')
            ->where('config_key', $key)
            ->first();

        if ($config === null) {
            return null;
        }

        $activeValue = DB::connection('platform')
            ->table('platform.configuration_values')
            ->where('config_key', $key)
            ->where('status', 'active')
            ->orderByDesc('created_at')
            ->first();

        $pendingRequest = DB::connection('platform')
            ->table('platform.configuration_change_requests')
            ->where('config_key', $key)
            ->where('status', 'pending_approval')
            ->first();

        $this->auditService->record(new AuditEventData(
            action:        'configuration.key.read',
            actorType:     'USER',
            resourceType:  'ConfigurationKey',
            correlationId: $correlationId,
            outcome:       'success',
            actorId:       $actorId,
            resourceId:    $key,
        ));

        return [
            'config_key'               => (string) $config->config_key,
            'domain'                   => (string) $config->domain,
            'current_value'            => $activeValue?->config_value,
            'value_type'               => $activeValue?->value_type,
            'status'                   => $activeValue?->status,
            'description'              => $config->description ?? null,
            'updated_at'               => $activeValue?->updated_at,
            'pending_change_request_id' => $pendingRequest?->id,
        ];
    }

    /**
     * @param array<string, mixed> $filters Optional filters: from, to, maker_id, checker_id, domain
     */
    public function getVersionHistory(
        string  $key,
        int     $perPage,
        ?string $actorId,
        string  $correlationId,
        array   $filters = [],
    ): array {
        $result = $this->versionRepository->paginateByKey(
            configKey:  $key,
            perPage:    $perPage,
            from:       $filters['from']       ?? null,
            to:         $filters['to']         ?? null,
            makerId:    $filters['maker_id']   ?? null,
            checkerId:  $filters['checker_id'] ?? null,
            domain:     $filters['domain']     ?? null,
        );

        $this->auditService->record(new AuditEventData(
            action:        'configuration.key.history_read',
            actorType:     'USER',
            resourceType:  'ConfigurationKey',
            correlationId: $correlationId,
            outcome:       'success',
            actorId:       $actorId,
            resourceId:    $key,
        ));

        return $result->toArray();
    }
}
