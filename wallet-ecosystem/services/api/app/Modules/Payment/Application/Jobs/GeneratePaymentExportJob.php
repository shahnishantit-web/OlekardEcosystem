<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Jobs;

use App\Modules\Payment\Domain\ValueObjects\CsvFormulaSanitiser;
use App\Modules\Payment\Infrastructure\Models\PaymentExportRequestModel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Ramsey\Uuid\Uuid;

final class GeneratePaymentExportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public $queue = 'payment';

    public function __construct(
        private readonly string $exportRequestId,
    ) {
    }

    public function handle(): void
    {
        $model = PaymentExportRequestModel::find($this->exportRequestId);

        if ($model === null) {
            Log::error('payment.export.model_not_found', ['id' => $this->exportRequestId]);
            return;
        }

        $model->status = 'generating';
        $model->save();

        try {
            $query = $this->buildQuery($model);
            $rows  = $query->get();

            $csv = $this->buildCsv($rows->all());

            $path = "exports/payment/{$model->id}.csv";
            Storage::put($path, $csv);

            $ttlMinutes = (int) config('payment.export.token_ttl_minutes', 60);

            $model->status         = 'ready';
            $model->token          = bin2hex(random_bytes(32));
            $model->row_count      = $rows->count();
            $model->generated_at   = Carbon::now();
            $model->token_expires_at = Carbon::now()->addMinutes($ttlMinutes);
            $model->save();
        } catch (\Throwable $e) {
            Log::error('payment.export.generation_failed', [
                'export_request_id' => $this->exportRequestId,
                'error'             => $e->getMessage(),
            ]);

            $model->status         = 'failed';
            $model->failure_reason = $e->getMessage();
            $model->save();
        }
    }

    private function buildQuery(PaymentExportRequestModel $model): \Illuminate\Database\Query\Builder
    {
        $query = DB::connection('payment')
            ->table('payment.payment_intents as pi')
            ->select([
                'pi.id as payment_reference',
                'pi.purpose_type',
                'pi.purpose_reference',
                'pi.organisation_id',
                'pi.payer_actor_id',
                'pi.currency_code',
                'pi.amount_minor',
                'pi.status',
                'pi.created_at',
                DB::raw("(
                    SELECT fee_minor
                    FROM payment.payment_attempts
                    WHERE payment_intent_id = pi.id AND status = 'succeeded'
                    ORDER BY confirmed_at DESC NULLS LAST
                    LIMIT 1
                ) as fee_minor"),
                DB::raw("(
                    SELECT confirmed_at
                    FROM payment.payment_attempts
                    WHERE payment_intent_id = pi.id AND status = 'succeeded'
                    ORDER BY confirmed_at DESC NULLS LAST
                    LIMIT 1
                ) as confirmed_at"),
                DB::raw("(
                    SELECT CASE WHEN COUNT(*) > 0 THEN 'provisional' ELSE 'reconciled' END
                    FROM payment.reconciliation_exceptions
                    WHERE payment_intent_id = pi.id
                      AND status NOT IN ('closed', 'resolved')
                ) as reconciliation_label"),
            ])
            ->orderByDesc('pi.created_at');

        if ($model->scope_type === 'organisation' && $model->organisation_id !== null) {
            $query->where('pi.organisation_id', $model->organisation_id);
        }

        $filters = $model->filters ?? [];

        if (! empty($filters['purpose_type'])) {
            $query->where('pi.purpose_type', $filters['purpose_type']);
        }

        if (! empty($filters['currency_code'])) {
            $query->where('pi.currency_code', $filters['currency_code']);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('pi.created_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('pi.created_at', '<=', $filters['date_to']);
        }

        if (! empty($filters['status'])) {
            $query->where('pi.status', $filters['status']);
        }

        return $query;
    }

    /** @param array<object> $rows */
    private function buildCsv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, [
            'payment_reference',
            'purpose_type',
            'purpose_reference',
            'organisation_id',
            'payer_actor_id',
            'currency_code',
            'amount_minor',
            'fee_minor',
            'status',
            'reconciliation_label',
            'created_at',
            'confirmed_at',
        ]);

        foreach ($rows as $row) {
            fputcsv($handle, [
                CsvFormulaSanitiser::sanitise((string) $row->payment_reference),
                CsvFormulaSanitiser::sanitise((string) $row->purpose_type),
                CsvFormulaSanitiser::sanitise((string) $row->purpose_reference),
                CsvFormulaSanitiser::sanitise((string) $row->organisation_id),
                CsvFormulaSanitiser::sanitise((string) $row->payer_actor_id),
                (string) $row->currency_code,
                (int) $row->amount_minor,
                $row->fee_minor !== null ? (int) $row->fee_minor : '',
                CsvFormulaSanitiser::sanitise((string) $row->status),
                (string) ($row->reconciliation_label ?? 'reconciled'),
                (string) $row->created_at,
                (string) ($row->confirmed_at ?? ''),
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return (string) $csv;
    }
}
