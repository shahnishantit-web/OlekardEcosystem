<?php

declare(strict_types=1);

namespace Tests\Performance\Modules\AgentFloat;

use Tests\TestCase;

/**
 * SC-E13-004: Transaction history endpoint returns 10,000-row dataset within 200ms.
 */
class FloatTransactionQueryPerfTest extends TestCase
{
    public function test_history_endpoint_under_200ms_with_10000_rows(): void
    {
        // Seed 10,000 float_transactions for a single agent
        // GET /v1/agents/{id}/float-wallet/transactions?per_page=50
        // Assert: response time ≤ 200ms; correct pagination meta
        $this->markTestIncomplete(
            'SC-E13-004: Seed 10,000 rows via factory, record microtime around HTTP call, assert ≤ 200ms.'
        );
    }

    public function test_composite_index_used_for_agent_date_query(): void
    {
        // EXPLAIN ANALYZE on agent_id + created_at DESC query must use idx_float_transactions_agent_created
        // Not a sequential scan
        $this->markTestIncomplete('Run DB::select("EXPLAIN...") and assert index scan in query plan.');
    }
}
