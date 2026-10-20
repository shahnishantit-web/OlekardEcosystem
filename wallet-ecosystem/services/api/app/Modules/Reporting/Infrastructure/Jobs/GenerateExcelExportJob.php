<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Jobs;

use App\Modules\Platform\Application\Services\OutboxEventPublisher;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Reporting\Domain\Events\ReportExportGeneratedEvent;
use App\Modules\Reporting\Infrastructure\Models\ReportExportModel;
use App\Modules\Reporting\Infrastructure\Models\ReportJobModel;
use App\Modules\Platform\Concerns\HasCorrelationId;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class GenerateExcelExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use HasCorrelationId;

    public int $timeout = 300;

    public function __construct(
        public readonly string $exportId,
        string $correlationId,
    ) {
        $this->correlationId = $correlationId;
    }

    public function handle(AuditServiceInterface $audit, OutboxEventPublisher $outbox): void
    {
        $this->injectCorrelationContext();

        $export = ReportExportModel::findOrFail($this->exportId);
        $export->status = 'generating';
        $export->save();

        try {
            $job = ReportJobModel::findOrFail($export->job_id);

            $resultData = $job->result_data ?? [];
            $rows       = $this->extractRows($resultData);

            $spreadsheet = new Spreadsheet();
            $sheet       = $spreadsheet->getActiveSheet();

            if (!empty($rows)) {
                $headers = array_keys((array) $rows[0]);
                $col     = 1;
                foreach ($headers as $header) {
                    $sheet->setCellValueByColumnAndRow($col++, 1, $header);
                }

                $headerRange = 'A1:' . \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers)) . '1';
                $sheet->getStyle($headerRange)->getFont()->setBold(true);

                $rowNum = 2;
                foreach ($rows as $row) {
                    $col = 1;
                    foreach (array_values((array) $row) as $cell) {
                        $sheet->setCellValueByColumnAndRow($col++, $rowNum, is_array($cell) ? json_encode($cell) : $cell);
                    }
                    $rowNum++;
                }
            }

            $tmpPath = tempnam(sys_get_temp_dir(), 'rpt_') . '.xlsx';
            $writer  = new Xlsx($spreadsheet);
            $writer->save($tmpPath);

            $storagePath = "exports/{$job->id}/{$this->exportId}.xlsx";
            $readHandle = fopen($tmpPath, 'r');
            if (! is_resource($readHandle)) {
                throw new \RuntimeException('Failed to read temp Excel file.');
            }
            Storage::disk('reports')->put($storagePath, $readHandle);
            @unlink($tmpPath);

            $fileSizeBytes      = Storage::disk('reports')->size($storagePath);
            $ttlSeconds         = (int) config('reports.export_link_ttl_seconds', 3600);
            $retentionDays      = (int) config('reports.export_file_retention_days', 7);
            $tokenExpiresAt     = now()->addSeconds($ttlSeconds);
            $retentionExpiresAt = $tokenExpiresAt->copy()->addDays($retentionDays);

            $token = hash_hmac(
                'sha256',
                "{$this->exportId}|{$tokenExpiresAt->timestamp}",
                config('app.key')
            );

            DB::connection('platform')->transaction(function () use ($export, $storagePath, $fileSizeBytes, $job, $token, $tokenExpiresAt, $retentionExpiresAt, $audit, $outbox): void {
                $export->status                    = 'ready';
                $export->storage_path              = $storagePath;
                $export->file_size_bytes           = $fileSizeBytes;
                $export->row_count                 = $job->row_count;
                $export->download_token            = $token;
                $export->token_expires_at          = $tokenExpiresAt;
                $export->file_retention_expires_at = $retentionExpiresAt;
                $export->save();

                $audit->record(new AuditEventData(
                    action:        'reporting.export.generated',
                    actorType:     'system',
                    resourceType:  'report_exports',
                    correlationId: $this->correlationId,
                    outcome:       'success',
                    actorId:       $export->created_by_user_id,
                    resourceId:    $export->id,
                    afterState:    ['format' => $export->format, 'row_count' => $export->row_count],
                ));

                $outbox->publish(new ReportExportGeneratedEvent(
                    exportId:      $export->id,
                    jobId:         $export->job_id,
                    format:        $export->format,
                    userId:        (string) ($export->created_by_user_id ?? ''),
                    correlationId: $this->correlationId,
                    occurredAt:    now()->toIso8601String(),
                ));
            });
        } catch (\Throwable $e) {
            $export->status = 'failed';
            $export->save();
            throw $e;
        }
    }

    private function extractRows(array $resultData): array
    {
        foreach (['wallets', 'transactions', 'users', 'agents', 'commissions', 'verifications', 'items', 'batches', 'currencies'] as $key) {
            if (isset($resultData[$key]) && is_array($resultData[$key])) {
                return $resultData[$key];
            }
        }

        foreach ($resultData as $value) {
            if (is_array($value) && array_is_list($value)) {
                return $value;
            }
        }

        return [$resultData];
    }
}
