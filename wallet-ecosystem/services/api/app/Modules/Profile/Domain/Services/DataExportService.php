<?php

declare(strict_types=1);

namespace App\Modules\Profile\Domain\Services;

use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Profile\Application\Commands\RequestDataExportCommand;
use App\Modules\Profile\Domain\Events\DataExportRequestedEvent;
use App\Modules\Profile\Domain\Exceptions\ExportInProgressException;
use App\Modules\Profile\Infrastructure\Jobs\ProcessDataExportJob;
use App\Modules\Profile\Infrastructure\Models\DataExportModel;
use App\Modules\Profile\Infrastructure\Repositories\EloquentDataExportRepository;

final class DataExportService
{
    public function __construct(
        private readonly EloquentDataExportRepository $exports,
        private readonly AuditServiceInterface        $audit,
        private readonly EventPublisherInterface      $events,
        private readonly ProfileMetrics               $metrics,
    ) {}

    /**
     * @throws ExportInProgressException when an export is already in progress for the user
     */
    public function request(RequestDataExportCommand $command): DataExportModel
    {
        $inProgress = $this->exports->findByUserIdAndStatus($command->userId, 'processing');
        if ($inProgress->isNotEmpty()) {
            throw new ExportInProgressException("An export is already in progress for user {$command->userId}");
        }

        $export = $this->exports->create([
            'user_id'        => $command->userId,
            'status'         => 'processing',
            'requested_at'   => now(),
            'correlation_id' => $command->correlationId,
        ]);

        ProcessDataExportJob::dispatch($export->id, $command->userId, $command->correlationId);

        $this->audit->record(new AuditEventData(
            action:       'profile.user.data-export-requested',
            actorType:    'USER',
            resourceType: 'DataExport',
            correlationId: $command->correlationId,
            outcome:      'success',
            actorId:      $command->userId,
            resourceId:   $export->id,
            metadata:     ['subject_user_id' => $command->userId],
        ));

        $this->events->publish(new DataExportRequestedEvent(
            userId:        $command->userId,
            correlationId: $command->correlationId,
            exportId:      $export->id,
        ));

        $this->metrics->dataExportRequested();

        return $export;
    }
}
