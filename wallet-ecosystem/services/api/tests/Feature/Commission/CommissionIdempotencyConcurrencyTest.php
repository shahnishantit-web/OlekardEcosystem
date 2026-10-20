<?php

declare(strict_types=1);

namespace Tests\Feature\Commission;

use App\Modules\Agent\Domain\Enums\AgentStatus;
use App\Modules\Agent\Infrastructure\Models\AgentModel;
use App\Modules\Commission\Application\Services\CommissionCalculationService;
use App\Modules\Commission\Infrastructure\Models\CommissionModel;
use App\Modules\Commission\Infrastructure\Models\CommissionRuleModel;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Simulates concurrent duplicate commission calculation to verify UNIQUE constraint
 * on idempotency_key prevents duplicate records.
 */
final class CommissionIdempotencyConcurrencyTest extends TestCase
{
    use DatabaseTransactions;

    public function test_concurrent_same_transaction_id_creates_exactly_one_commission(): void
    {
        $ledger = $this->createMock(LedgerModuleContract::class);
        $ledger->method('registerAccount');
        $ledger->method('postEntry')->willReturn('journal-concurrent');

        $config = $this->createMock(ConfigurationServiceInterface::class);
        $config->method('get')->willReturn('commission_expense:BWP');

        $this->instance(AuditServiceInterface::class, $this->createStub(AuditServiceInterface::class));
        $this->instance(EventPublisherInterface::class, $this->createStub(EventPublisherInterface::class));
        $this->instance(LedgerModuleContract::class, $ledger);
        $this->instance(ConfigurationServiceInterface::class, $config);

        $agent = AgentModel::factory()->create(['status' => AgentStatus::Active->value, 'agent_tier' => null]);
        CommissionRuleModel::factory()->create([
            'rule_type'  => 'flat',
            'rate'       => 200,
            'applies_to' => 'cash_in',
            'status'     => 'active',
            'valid_from' => now()->subDay()->toDateString(),
            'valid_to'   => null,
            'agent_tier' => null,
        ]);

        $service = $this->app->make(CommissionCalculationService::class);

        $results = [];
        for ($i = 0; $i < 5; $i++) {
            try {
                $results[] = $service->calculate(
                    agentId:         $agent->id,
                    transactionId:   'concurrent-txn',
                    transactionType: 'cash_in',
                    amount:          50000,
                    currencyCode:    'BWP',
                    walletId:        'wallet-concurrent',
                    correlationId:   "corr-concurrent-{$i}",
                );
            } catch (\Throwable) {
                $results[] = null;
            }
        }

        $this->assertEquals(
            1,
            CommissionModel::where('idempotency_key', 'concurrent-txn:cash_in')->count(),
            'Exactly one commission record must exist regardless of concurrent calls',
        );
    }
}
