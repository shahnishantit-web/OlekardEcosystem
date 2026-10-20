<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\AgentFloat;

use Tests\TestCase;

class FloatTransactionHistoryTest extends TestCase
{
    public function test_returns_paginated_transaction_history(): void
    {
        // GET /float-wallet/transactions → 200 with data[] and meta pagination
        $this->markTestIncomplete('Seed 15 float_transactions; verify meta.total=15.');
    }

    public function test_filters_by_transaction_type(): void
    {
        // ?type=funding → only funding records returned
        $this->markTestIncomplete('Seed mixed types; assert type filter applied.');
    }

    public function test_filters_by_date_range(): void
    {
        // ?from=2026-09-01&to=2026-09-15 → only records in range
        $this->markTestIncomplete('Seed transactions across multiple dates.');
    }

    public function test_all_transactions_have_non_null_journal_id(): void
    {
        // T066 orphan assertion: every float_transaction row must reference a real ledger journal
        // journal_id must be non-null and match a journals record
        $this->markTestIncomplete('Assert journal_id non-null in each record; cross-check against journals table.');
    }

    public function test_empty_result_returns_200_with_empty_data(): void
    {
        // No transactions → 200; data=[]; meta.total=0
        $this->markTestIncomplete('Request history for agent with no transactions.');
    }

    public function test_unauthorized_returns_403(): void
    {
        // Agent token without transactions.view → 403
        $this->markTestIncomplete('Wire up agent token without agent_float.transactions.view permission.');
    }
}
