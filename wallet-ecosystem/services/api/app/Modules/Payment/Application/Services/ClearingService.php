<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Services;

use App\Modules\Payment\Domain\Contracts\ClearingRecordRepositoryInterface;
use App\Modules\Payment\Infrastructure\Models\ClearingRecordModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

final class ClearingService
{
    public function __construct(
        private readonly ClearingRecordRepositoryInterface $repository,
        private readonly ConfigurationServiceInterface     $config,
        private readonly AuditServiceInterface             $audit,
    ) {}

    public function createFromMatch(
        string $attemptId,
        string $statementId,
        string $statementLineId,
        string $providerCode,
        string $currencyCode,
        int    $clearedAmountMinor,
        int    $attemptAmountMinor,
    ): ClearingRecordModel {
        $accountingStatus = $this->resolveAccountingStatus($providerCode);

        $record = $this->repository->create([
            'id'                   => (string) Uuid::uuid7(),
            'payment_attempt_id'   => $attemptId,
            'statement_id'         => $statementId,
            'statement_line_id'    => $statementLineId,
            'provider_code'        => $providerCode,
            'currency_code'        => $currencyCode,
            'cleared_amount_minor' => $clearedAmountMinor,
            'attempt_amount_minor' => $attemptAmountMinor,
            'clearing_status'      => 'cleared',
            'accounting_status'    => $accountingStatus,
            'cleared_at'           => now(),
        ]);

        $this->audit->record(new AuditEventData(
            action:       'payment.clearing.created',
            actorType:    'system',
            resourceType: 'clearing_record',
            correlationId: $record->id,
            outcome:      'success',
            resourceId:   $record->id,
            afterState:   [
                'payment_attempt_id' => $attemptId,
                'clearing_status'    => 'cleared',
                'accounting_status'  => $accountingStatus,
            ],
        ));

        return $record;
    }

    /**
     * Process a bank settlement batch and transition matched ClearingRecords to bank_settled.
     *
     * @param array<array{provider_reference_id: string, amount_minor: int}> $lines
     * @return array{matched: int, unmatched: int}
     */
    public function matchBankSettlementBatch(
        string $providerCode,
        string $batchRef,
        Carbon $settledAt,
        array  $lines,
    ): array {
        $matched   = 0;
        $unmatched = 0;

        DB::connection('payment')->transaction(function () use (
            $providerCode, $batchRef, $settledAt, $lines, &$matched, &$unmatched
        ): void {
            foreach ($lines as $line) {
                $providerRef = $line['provider_reference_id'] ?? null;
                if ($providerRef === null) {
                    $unmatched++;
                    continue;
                }

                // Find by joining statement lines to clearing records
                $record = ClearingRecordModel::query()
                    ->where('provider_code', $providerCode)
                    ->whereHas('statement', function ($q) use ($providerRef): void {
                        $q->whereHas('lines', function ($q2) use ($providerRef): void {
                            $q2->where('provider_reference_id', $providerRef);
                        });
                    })
                    ->first();

                if ($record === null) {
                    Log::warning('bank_settlement.unmatched_line', [
                        'provider_code'        => $providerCode,
                        'provider_reference_id' => $providerRef,
                        'batch_ref'            => $batchRef,
                    ]);
                    $unmatched++;
                    continue;
                }

                ClearingRecordModel::query()->where('id', $record->id)->update([
                    'clearing_status'           => 'bank_settled',
                    'bank_settlement_batch_ref' => $batchRef,
                    'bank_settled_at'           => $settledAt,
                    'updated_at'                => now(),
                ]);
                $matched++;
            }

            $this->audit->record(new AuditEventData(
                action:       'payment.clearing.bank_settled',
                actorType:    'system',
                resourceType: 'clearing_batch',
                correlationId: $batchRef,
                outcome:      'success',
                afterState:   [
                    'provider_code' => $providerCode,
                    'batch_ref'     => $batchRef,
                    'matched'       => $matched,
                    'unmatched'     => $unmatched,
                ],
            ));
        });

        return ['matched' => $matched, 'unmatched' => $unmatched];
    }

    private function resolveAccountingStatus(string $providerCode): string
    {
        /** @var array<string> $pendingProviders */
        $pendingProviders = config('payment.pending_ledger_config_providers', []);

        if (in_array($providerCode, $pendingProviders, true)) {
            return 'pending_ledger_config';
        }

        return 'qualified';
    }
}
