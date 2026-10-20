<?php

declare(strict_types=1);

namespace App\Modules\Admin\Application\Jobs;

use App\Modules\Admin\Application\Services\ExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class ExportTransactionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 300; // 5 minutes

    public function __construct(
        private readonly string $exportId,
        private readonly array  $filters,
        private readonly string $format,
        private readonly string $requestedBy,
    ) {}

    public function handle(ExportService $exportService): void
    {
        try {
            $query = DB::connection('platform')->table('transaction.transaction_projections as t')
                ->leftJoin('identity.users as u', 'u.id', '=', 't.customer_id')
                ->select([
                    't.id', 't.reference', 't.type', 't.status', 't.amount',
                    't.currency_code', 't.direction', 't.wallet_id', 't.customer_id',
                    't.source_module', 't.created_at', 't.completed_at', 't.failed_at',
                    'u.email as customer_email',
                ]);

            $this->applyFilters($query);

            $fileName = "exports/admin/transactions_{$this->exportId}.{$this->format}";
            $rows     = 0;

            if ($this->format === 'csv') {
                $rows = $this->writeCsv($query, $fileName);
            } else {
                $rows = $this->writeJson($query, $fileName);
            }

            $exportService->markCompleted($this->exportId, $fileName, $rows, $this->format);
        } catch (\Throwable $e) {
            Log::error('ExportTransactionsJob failed', [
                'export_id' => $this->exportId,
                'error'     => $e->getMessage(),
            ]);

            $exportService->markFailed($this->exportId, $e->getMessage());
        }
    }

    private function applyFilters(mixed $query): void
    {
        if (! empty($this->filters['type'])) {
            $query->where('t.type', $this->filters['type']);
        }

        if (! empty($this->filters['status']) && $this->filters['status'] !== 'all') {
            $query->where('t.status', $this->filters['status']);
        }

        if (! empty($this->filters['from'])) {
            $query->where('t.created_at', '>=', $this->filters['from']);
        }

        if (! empty($this->filters['to'])) {
            $query->where('t.created_at', '<=', $this->filters['to'] . ' 23:59:59');
        }

        if (! empty($this->filters['wallet_id'])) {
            $query->where('t.wallet_id', $this->filters['wallet_id']);
        }

        $query->orderBy('t.created_at', 'desc');
    }

    private function writeCsv(mixed $query, string $fileName): int
    {
        $disk    = config('filesystems.disks.export', 'local');
        $rows    = 0;
        $headers = ['id', 'reference', 'type', 'status', 'amount', 'currency_code', 'direction', 'wallet_id', 'customer_id', 'customer_email', 'source_module', 'created_at', 'completed_at', 'failed_at'];

        $tmpFile = tempnam(sys_get_temp_dir(), 'export_');
        $handle  = fopen($tmpFile, 'w');

        fputcsv($handle, $headers);

        $query->chunk(1000, function ($records) use ($handle, &$rows): void {
            foreach ($records as $record) {
                fputcsv($handle, (array) $record);
                $rows++;
            }
        });

        fclose($handle);

        Storage::disk($disk)->put($fileName, file_get_contents($tmpFile));
        unlink($tmpFile);

        return $rows;
    }

    private function writeJson(mixed $query, string $fileName): int
    {
        $disk = config('filesystems.disks.export', 'local');
        $rows = 0;
        $all  = [];

        $query->chunk(1000, function ($records) use (&$all, &$rows): void {
            foreach ($records as $record) {
                $all[] = (array) $record;
                $rows++;
            }
        });

        Storage::disk($disk)->put($fileName, json_encode($all, JSON_PRETTY_PRINT));

        return $rows;
    }
}
