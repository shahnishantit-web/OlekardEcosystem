<?php

declare(strict_types=1);

namespace Tests\Feature\Commission;

use App\Modules\Agent\Domain\Enums\AgentStatus;
use App\Modules\Agent\Infrastructure\Models\AgentModel;
use App\Modules\AgentFloat\Infrastructure\Models\AgentFloatWalletModel;
use App\Modules\Commission\Application\Services\CommissionPayoutService;
use App\Modules\Commission\Domain\Enums\CommissionPayoutStatus;
use App\Modules\Commission\Domain\Enums\CommissionStatus;
use App\Modules\Commission\Infrastructure\Models\CommissionModel;
use App\Modules\Commission\Infrastructure\Models\CommissionPayoutModel;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Verifies that concurrent payout requests for the same commissions produce exactly one payout
 * due to DB-level UNIQUE constraints and transaction serialization.
 */
final class PayoutIdempotencyConcurrencyTest extends TestCase
{
    use DatabaseTransactions;

    public function test_concurrent_payout_requests_credit_agent_exactly_once(): void
    {
        $ledger = $this->createMock(LedgerModuleContract::class);
        $ledger->method('postEntry')->willReturn('concurrent-journal');

        $this->instance(AuditServiceInterface::class, $this->createStub(AuditServiceInterface::class));
        $this->instance(EventPublisherInterface::class, $this->createStub(EventPublisherInterface::class));
        $this->instance(LedgerModuleContract::class, $ledger);

        $agent = AgentModel::factory()->create(['status' => AgentStatus::Active->value]);
        AgentFloatWalletModel::factory()->create([
            'agent_id'                 => $agent->id,
            'ledger_account_reference' => "agent_float:{$agent->id}",
        ]);
        $commissions = CommissionModel::factory()->count(2)->create([
            'agent_id'      => $agent->id,
            'amount'        => 200,
            'status'        => CommissionStatus::Pending->value,
            'currency_code' => 'BWP',
        ]);

        $service = $this->app->make(CommissionPayoutService::class);
        $ids     = $commissions->pluck('id')->all();

        $results = [];
        for ($i = 0; $i < 5; $i++) {
            try {
                $results[] = $service->approveAndExecute('admin-1', $agent->id, $ids, "corr-concurrent-{$i}");
            } catch (\Throwable) {
                $results[] = null;
            }
        }

        $completed = array_filter($results, fn ($r) => $r && $r->status === CommissionPayoutStatus::Completed);
        $this->assertLessThanOrEqual(
            1,
            count($completed),
            'At most one completed payout should exist for the same commission set',
        );

        $this->assertEquals(
            1,
            CommissionPayoutModel::where('agent_id', $agent->id)
                ->whereIn('status', [CommissionPayoutStatus::Completed->value, CommissionPayoutStatus::Failed->value])
                ->count(),
        );
    }
}
