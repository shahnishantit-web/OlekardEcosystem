<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Services;

use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Reporting\Domain\Enums\ReportJobStatus;
use App\Modules\Reporting\Domain\Exceptions\ReportJobNotCompleteException;
use App\Modules\Reporting\Domain\Exceptions\ReportJobNotFoundException;
use App\Modules\Reporting\Infrastructure\Jobs\GenerateExcelExportJob;
use App\Modules\Reporting\Infrastructure\Jobs\GenerateReportExportJob;
use App\Modules\Reporting\Infrastructure\Models\ReportExportModel;
use App\Modules\Reporting\Infrastructure\Models\ReportJobModel;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;

final class ReportExportApplicationService
{
    public function __construct(
        private readonly AuditServiceInterface $audit,
    ) {}

    public function requestExport(
        string $jobId,
        string $userId,
        string $format,
        string $correlationId,
    ): ReportExportModel {
        $job = ReportJobModel::where('id', $jobId)
            ->where('requested_by_user_id', $userId)
            ->first();

        if ($job === null) {
            throw new ReportJobNotFoundException();
        }

        if ($job->status !== ReportJobStatus::Complete) {
            throw new ReportJobNotCompleteException();
        }

        $export = ReportExportModel::create([
            'job_id'              => $jobId,
            'format'              => $format,
            'status'              => 'pending',
            'created_by_user_id'  => $userId,
        ]);

        if ($format === 'excel') {
            GenerateExcelExportJob::dispatch($export->id, $correlationId);
        } else {
            GenerateReportExportJob::dispatch($export->id, $correlationId);
        }

        $this->audit->record(new AuditEventData(
            action:        'reporting.export.requested',
            actorType:     'user',
            resourceType:  'report_exports',
            correlationId: $correlationId,
            outcome:       'success',
            actorId:       $userId,
            resourceId:    $export->id,
            afterState:    ['format' => $format, 'job_id' => $jobId],
        ));

        return $export;
    }

    public function getExport(string $exportId, string $userId): array
    {
        $export = ReportExportModel::findOrFail($exportId);
        $job    = ReportJobModel::find($export->job_id);

        if ($job === null || $job->requested_by_user_id !== $userId) {
            throw new ReportJobNotFoundException();
        }

        if ($export->isPurged()) {
            throw new GoneHttpException('Export file has been purged.');
        }

        $downloadUrl = null;
        if ($export->status === 'ready' && !$export->isLinkExpired()) {
            $downloadUrl = $this->buildDownloadUrl($export);
        }

        return [
            'export'       => $export,
            'download_url' => $downloadUrl,
        ];
    }

    public function regenerateLink(
        string $exportId,
        string $adminUserId,
        string $correlationId,
    ): ReportExportModel {
        $export = ReportExportModel::findOrFail($exportId);

        if ($export->isPurged()) {
            throw new GoneHttpException('Export file has been purged.');
        }

        if (!$export->isFileRetained()) {
            throw new GoneHttpException('Export file retention period has expired.');
        }

        $ttlSeconds     = (int) config('reports.export_link_ttl_seconds', 3600);
        $retentionDays  = (int) config('reports.export_file_retention_days', 7);
        $tokenExpiresAt = now()->addSeconds($ttlSeconds);

        $export->download_token            = hash_hmac(
            'sha256',
            "{$exportId}|{$tokenExpiresAt->timestamp}",
            config('app.key')
        );
        $export->token_expires_at          = $tokenExpiresAt;
        $export->file_retention_expires_at = $tokenExpiresAt->copy()->addDays($retentionDays);
        $export->save();

        $this->audit->record(new AuditEventData(
            action:        'reporting.export.link_regenerated',
            actorType:     'user',
            resourceType:  'report_exports',
            correlationId: $correlationId,
            outcome:       'success',
            actorId:       $adminUserId,
            resourceId:    $export->id,
        ));

        return $export;
    }

    private function buildDownloadUrl(ReportExportModel $export): string
    {
        $disk = config('filesystems.disks.reports.driver', 'local');

        if ($disk === 's3') {
            return Storage::disk('reports')->temporaryUrl(
                $export->storage_path ?? '',
                $export->token_expires_at ?? now()->addHour()
            );
        }

        $expiry = $export->token_expires_at?->timestamp ?? now()->addHour()->timestamp;
        return url("/v1/reports/exports/{$export->id}/download?token={$export->download_token}&expires={$expiry}");
    }
}
