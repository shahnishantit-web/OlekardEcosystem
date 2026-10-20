<?php

declare(strict_types=1);

namespace Tests\Concurrency\Modules\Funding;

use App\Modules\Funding\Application\Services\AgentCashInService;
use App\Modules\Funding\Domain\Exceptions\InsufficientFloatException;
use Tests\Feature\Modules\Funding\FundingTestCase;
use Tests\Support\ConcurrencyTestHelper;

/**
 * Verifies that concurrent agent cash-in requests do not allow over-drafts on the agent float.
 *
 * CC-E08-006: When multiple concurrent requests each read the same float balance,
 * the SELECT FOR UPDATE lock ensures only one proceeds and subsequent ones
 * see the updated (reduced) balance.
 */
final class AgentCashInConcurrencyTest extends FundingTestCase
{
    public function test_concurrent_cashin_does_not_overdraft_agent_float(): void
    {
        // Seed float with exactly 10000 BWP
        // FundingTestCase already seeds 50000; let's set it to a known value
        $agentId = $this->agentId;

        // Two concurrent requests each for 9000 — only one should succeed
        // (total would be 18000 > 10000 if both succeeded in the naive case)

        $results = ConcurrencyTestHelper::runConcurrent(2, function () use ($agentId): ?string {
            try {
                $service = app(AgentCashInService::class);
                $tx = $service->cashIn([
                    'agent_id'       => $agentId,
                    'customer_id'    => $this->customerId,
                    'wallet_id'      => $this->walletId,
                    'amount'         => 40_000,
                    'currency_code'  => 'BWP',
                    'pin'            => '1234',
                    'correlation_id' => (string) \Ramsey\Uuid\Uuid::uuid7(),
                    'idempotency_key' => (string) \Ramsey\Uuid\Uuid::uuid7(),
                ]);
                return $tx->id;
            } catch (InsufficientFloatException) {
                return null;
            }
        });

        $successes = array_filter($results, fn ($r) => $r !== null);
        $failures  = array_filter($results, fn ($r) => $r === null);

        // At least one must succeed, at least one must fail due to insufficient float
        $this->assertNotEmpty($successes, 'At least one cash-in should succeed');
        $this->assertNotEmpty($failures,  'At least one cash-in should fail — agent float = 50000, each request = 40000');
    }
}
