<?php

declare(strict_types=1);

namespace Tests\Unit\Reconciliation;

use App\Modules\Reconciliation\Application\Adapters\CsvProviderFileAdapter;
use Tests\TestCase;

/**
 * T049: Tests for bank statement import.
 * Validates signed-amount semantics: positive = deposit/credit, negative = withdrawal/debit.
 * Full integration scenarios require Docker.
 */
class BankStatementTest extends TestCase
{
    public function test_csv_adapter_parses_positive_amount_as_deposit(): void
    {
        $csv    = "provider_reference,amount,currency_code,transaction_date\nTX001,250.0000,BWP,2026-09-27\n";
        $stream = fopen('data://text/plain,' . $csv, 'rb');

        $adapter = new CsvProviderFileAdapter();
        $dtos    = $adapter->parse($stream, 'text/csv');
        fclose($stream);

        $this->assertCount(1, $dtos);
        $this->assertEquals('250.0000', $dtos[0]->amount);
        $this->assertGreaterThan(0, (float) $dtos[0]->amount);
    }

    public function test_csv_adapter_parses_negative_amount_as_withdrawal(): void
    {
        $csv    = "provider_reference,amount,currency_code,transaction_date\nTX002,-100.0000,BWP,2026-09-27\n";
        $stream = fopen('data://text/plain,' . $csv, 'rb');

        $adapter = new CsvProviderFileAdapter();
        $dtos    = $adapter->parse($stream, 'text/csv');
        fclose($stream);

        $this->assertCount(1, $dtos);
        $this->assertEquals('-100.0000', $dtos[0]->amount);
        $this->assertLessThan(0, (float) $dtos[0]->amount);
    }

    public function test_csv_adapter_skips_empty_lines(): void
    {
        $csv    = "provider_reference,amount,currency_code,transaction_date\nTX001,100.0000,BWP,2026-09-27\n\n";
        $stream = fopen('data://text/plain,' . $csv, 'rb');

        $adapter = new CsvProviderFileAdapter();
        $dtos    = $adapter->parse($stream, 'text/csv');
        fclose($stream);

        $this->assertCount(1, $dtos);
    }

    public function test_csv_adapter_returns_empty_for_header_only(): void
    {
        $csv    = "provider_reference,amount,currency_code,transaction_date\n";
        $stream = fopen('data://text/plain,' . $csv, 'rb');

        $adapter = new CsvProviderFileAdapter();
        $dtos    = $adapter->parse($stream, 'text/csv');
        fclose($stream);

        $this->assertCount(0, $dtos);
    }

    public function test_null_settlement_adapter_returns_synthetic_reference(): void
    {
        $adapter    = new \App\Modules\Reconciliation\Application\Adapters\NullSettlementAdapter();
        $settlement = new \App\Modules\Reconciliation\Domain\Models\SettlementBatch();
        $settlement->id = 'test-settlement-id';

        $ref = $adapter->execute($settlement);

        $this->assertStringStartsWith('NULL-ADAPTER-', $ref);
    }
}
