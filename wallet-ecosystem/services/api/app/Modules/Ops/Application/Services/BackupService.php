<?php

declare(strict_types=1);

namespace App\Modules\Ops\Application\Services;

use App\Modules\Audit\Application\Services\AuditService;
use App\Modules\Audit\Domain\Enums\AuditActorType;
use App\Modules\Audit\Domain\Enums\AuditEventCategory;
use App\Modules\Audit\Domain\Enums\AuditOutcome;
use App\Modules\Audit\Domain\ValueObjects\AuditEntry;
use App\Modules\Ops\Domain\Contracts\AlertRuleRepositoryInterface;
use App\Modules\Ops\Domain\Contracts\BackupJobRepositoryInterface;
use App\Modules\Ops\Domain\Contracts\BackupStorageAdapterInterface;
use App\Modules\Ops\Domain\Enums\BackupJobStatus;
use App\Modules\Ops\Domain\Enums\BackupType;
use App\Modules\Ops\Infrastructure\Models\AlertRuleModel;
use App\Modules\Ops\Infrastructure\Models\BackupJobModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class BackupService
{
    public function __construct(
        private readonly BackupJobRepositoryInterface $jobs,
        private readonly BackupStorageAdapterInterface $storage,
        private readonly AlertDispatchService $alertDispatch,
        private readonly AlertRuleRepositoryInterface $alertRules,
        private readonly AuditService $audit,
    ) {}

    public function run(string $idempotencyKey, BackupType $type = BackupType::Full): BackupJobModel
    {
        $existing = $this->jobs->findByIdempotencyKey($idempotencyKey);

        if ($existing !== null) {
            return $existing;
        }

        $job = $this->jobs->create($idempotencyKey, $type);

        try {
            $this->jobs->markRunning($job->id);

            $data = $this->collectBackupData($type);

            $storagePath = $this->storage->store($data);
            $sizeBytes   = strlen(json_encode($data) ?: '');

            $completed = $this->jobs->markCompleted($job->id, $storagePath, $sizeBytes);

            return $completed;
        } catch (\Throwable $e) {
            $failed = $this->jobs->markFailed($job->id, $e->getMessage());

            $this->notifyBackupFailure($job->id, $e->getMessage());

            Log::error('Backup job failed', ['job_id' => $job->id, 'error' => $e->getMessage()]);

            return $failed;
        }
    }

    public function verify(string $jobId, string $actorId, string $correlationId): array
    {
        $job = $this->jobs->findOrFail($jobId);

        if ($job->storage_path === null) {
            return ['verified' => false, 'reason' => 'Backup has no storage location (not completed).'];
        }

        $verified = $this->storage->verify($job->storage_path);

        $this->audit->record(
            AuditEntry::make(
                category:      AuditEventCategory::Admin,
                eventType:     'ops.backup.verified',
                actorType:     AuditActorType::Admin,
                action:        'verify_backup',
                outcome:       $verified ? AuditOutcome::Success : AuditOutcome::Failure,
                correlationId: $correlationId,
            )
            ->withActor($actorId)
            ->withEntity('BackupJob', $jobId)
            ->withAfterState(['storage_path' => $job->storage_path, 'verified' => $verified])
        );

        return [
            'verified'     => $verified,
            'storage_path' => $job->storage_path,
            'checked_at'   => now()->toAtomString(),
        ];
    }

    private function collectBackupData(BackupType $type): array
    {
        return [
            'type'           => $type->value,
            'generated_at'   => now()->toAtomString(),
            'schema_version' => '1.0',
            'placeholder'    => 'Full database backup should use pg_dump via shell in production',
        ];
    }

    private function notifyBackupFailure(string $jobId, string $errorMessage): void
    {
        try {
            $rules = $this->alertRules->allEnabled();

            $matchingRule = $rules->first(fn (AlertRuleModel $r) =>
                isset($r->metric_condition['event']) && $r->metric_condition['event'] === 'backup.failed'
            );

            if ($matchingRule === null) {
                return;
            }

            $this->alertDispatch->fire(
                $matchingRule,
                "Backup job {$jobId} failed: {$errorMessage}"
            );
        } catch (\Throwable $e) {
            Log::warning('Failed to dispatch backup failure alert', ['error' => $e->getMessage()]);
        }
    }
}
