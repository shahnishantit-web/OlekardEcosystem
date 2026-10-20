<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Reconciliation\Domain\Enums\BatchSourceType;
use App\Modules\Reconciliation\Domain\Enums\BatchStatus;
use App\Modules\Reconciliation\Domain\Enums\ExceptionStatus;
use App\Modules\Reconciliation\Domain\Enums\ExceptionType;
use App\Modules\Reconciliation\Domain\Enums\ItemStatus;
use App\Modules\Reconciliation\Domain\Enums\SettlementStatus;
use App\Modules\Reconciliation\Domain\Models\ReconciliationBatch;
use App\Modules\Reconciliation\Domain\Models\ReconciliationException;
use App\Modules\Reconciliation\Domain\Models\ReconciliationItem;
use App\Modules\Reconciliation\Domain\Models\SettlementBatch;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeds reconciliation module with test data for Docker quickstart validation.
 * SC-1 through SC-8 scenarios.
 */
final class ReconciliationTestSeeder extends Seeder
{
    public function run(): void
    {
        $systemUserId = '00000000-0000-0000-0000-000000000001';
        $makerId      = '00000000-0000-0000-0000-000000000002';
        $checkerId    = '00000000-0000-0000-0000-000000000003';

        // SC-1: Provider file batch (completed with matches)
        $batchId = (string) Str::orderedUuid();
        $batch   = ReconciliationBatch::on('reconciliation')->create([
            'id'               => $batchId,
            'idempotency_key'  => 'test-sc1-batch',
            'source_type'      => BatchSourceType::ProviderFile->value,
            'source_reference' => 'test_provider_20260927.csv',
            'batch_date'       => '2026-09-27',
            'status'           => BatchStatus::Completed->value,
            'total_count'      => 3,
            'matched_count'    => 2,
            'unmatched_count'  => 0,
            'exception_count'  => 1,
            'submitted_by'     => $systemUserId,
            'processed_at'     => now(),
            'metadata'         => [],
        ]);

        $item1Id = (string) Str::orderedUuid();
        ReconciliationItem::on('reconciliation')->create([
            'id'                      => $item1Id,
            'batch_id'                => $batchId,
            'item_type'               => 'provider_transaction',
            'provider_reference'      => 'PROV-TX-001',
            'internal_transaction_id' => (string) Str::orderedUuid(),
            'amount'                  => '100.0000',
            'currency_code'           => 'BWP',
            'transaction_date'        => '2026-09-27',
            'status'                  => ItemStatus::Matched->value,
            'match_confidence'        => 100,
            'matched_at'              => now(),
            'metadata'                => [],
        ]);

        $item2Id = (string) Str::orderedUuid();
        ReconciliationItem::on('reconciliation')->create([
            'id'                      => $item2Id,
            'batch_id'                => $batchId,
            'item_type'               => 'provider_transaction',
            'provider_reference'      => 'PROV-TX-002',
            'internal_transaction_id' => (string) Str::orderedUuid(),
            'amount'                  => '250.5000',
            'currency_code'           => 'BWP',
            'transaction_date'        => '2026-09-27',
            'status'                  => ItemStatus::Matched->value,
            'match_confidence'        => 75,
            'matched_at'              => now(),
            'metadata'                => [],
        ]);

        $item3Id = (string) Str::orderedUuid();
        ReconciliationItem::on('reconciliation')->create([
            'id'               => $item3Id,
            'batch_id'         => $batchId,
            'item_type'        => 'provider_transaction',
            'provider_reference' => 'PROV-TX-003',
            'amount'           => '75.0000',
            'currency_code'    => 'BWP',
            'transaction_date' => '2026-09-27',
            'status'           => ItemStatus::Exception->value,
            'match_confidence' => 0,
            'metadata'         => [],
        ]);

        ReconciliationException::on('reconciliation')->create([
            'id'             => (string) Str::orderedUuid(),
            'item_id'        => $item3Id,
            'batch_id'       => $batchId,
            'exception_type' => ExceptionType::UnmatchedProvider->value,
            'status'         => ExceptionStatus::Open->value,
            'opened_by'      => $systemUserId,
            'opened_at'      => now(),
        ]);

        // SC-6: Bank statement batch
        $bankBatchId = (string) Str::orderedUuid();
        ReconciliationBatch::on('reconciliation')->create([
            'id'               => $bankBatchId,
            'idempotency_key'  => 'test-sc6-bank-batch',
            'source_type'      => BatchSourceType::BankStatement->value,
            'source_reference' => 'bank_statement_20260927.csv',
            'batch_date'       => '2026-09-27',
            'status'           => BatchStatus::Matched->value,
            'total_count'      => 2,
            'matched_count'    => 1,
            'unmatched_count'  => 1,
            'exception_count'  => 0,
            'submitted_by'     => $systemUserId,
            'processed_at'     => now(),
            'metadata'         => [],
        ]);

        // SC-7: Draft settlement
        SettlementBatch::on('reconciliation')->create([
            'id'              => (string) Str::orderedUuid(),
            'idempotency_key' => 'test-sc7-settlement',
            'settlement_type' => 'provider_settlement',
            'period_start'    => '2026-09-01',
            'period_end'      => '2026-09-27',
            'currency_code'   => 'BWP',
            'gross_amount'    => '350.5000',
            'fee_amount'      => '0.0000',
            'net_amount'      => '350.5000',
            'status'          => SettlementStatus::Draft->value,
            'maker_id'        => $makerId,
            'metadata'        => [],
        ]);
    }
}
