<?php

declare(strict_types=1);

namespace Tests\Feature\Commission;

use App\Modules\Agent\Domain\Enums\AgentStatus;
use App\Modules\Agent\Infrastructure\Models\AgentModel;
use App\Modules\AgentFloat\Domain\Events\FloatCashInEvent;
use App\Modules\AgentFloat\Domain\Events\FloatCashOutEvent;
use App\Modules\Commission\Infrastructure\Models\CommissionModel;
use App\Modules\Commission\Infrastructure\Models\CommissionRuleModel;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

final class CommissionCalculationListenerTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->instance(AuditServiceInterface::class, $this->createStub(AuditServiceInterface::class));

        $events = $this->createStub(EventPublisherInterface::class);
        $this->instance(EventPublisherInterface::class, $events);

        $config = $this->createMock(ConfigurationServiceInterface::class);
        $config->method('get')->willReturn('commission_expense:BWP');
        $this->instance(ConfigurationServiceInterface::class, $config);

        $ledger = $this->createMock(LedgerModuleContract::class);
        $ledger->method('registerAccount');
        $ledger->method('postEntry')->willReturn('mock-journal-id');
        $this->instance(LedgerModuleContract::class, $ledger);
    }

    private function makeAgent(): AgentModel
    {
        return AgentModel::factory()->create(['status' => AgentStatus::Active->value, 'agent_tier' => null]);
    }

    private function makeRule(string $type = 'cash_in'): void
    {
        CommissionRuleModel::factory()->create([
            'rule_type'  => 'flat',
            'rate'       => 200,
            'applies_to' => $type,
            'status'     => 'active',
            'valid_from' => now()->subDay()->toDateString(),
            'valid_to'   => null,
            'agent_tier' => null,
        ]);
    }

    public function test_cash_in_event_creates_commission_record(): void
    {
        $agent = $this->makeAgent();
        $this->makeRule('cash_in');

        event(new FloatCashInEvent(
            agentId:            $agent->id,
            customerId:         'cust-1',
            walletId:           'wallet-1',
            amount:             50000,
            currencyCode:       'BWP',
            journalId:          'float-journal-1',
            floatTransactionId: 'float-txn-1',
            correlationId:      'corr-cash-in-1',
        ));

        $this->assertEquals(1, CommissionModel::count());
        $commission = CommissionModel::first();
        $this->assertEquals(200, $commission->amount);
        $this->assertEquals('cash_in', $commission->transaction_type->value);
    }

    public function test_cash_out_event_creates_commission_record(): void
    {
        $agent = $this->makeAgent();
        $this->makeRule('cash_out');

        event(new FloatCashOutEvent(
            agentId:            $agent->id,
            customerId:         'cust-1',
            walletId:           'wallet-1',
            amount:             75000,
            currencyCode:       'BWP',
            journalId:          'float-journal-2',
            floatTransactionId: 'float-txn-2',
            correlationId:      'corr-cash-out-1',
        ));

        $this->assertEquals(1, CommissionModel::count());
        $this->assertEquals('cash_out', CommissionModel::first()->transaction_type->value);
    }

    public function test_replay_with_same_transaction_id_creates_no_duplicate(): void
    {
        $agent = $this->makeAgent();
        $this->makeRule('cash_in');

        $fireEvent = fn () => event(new FloatCashInEvent(
            agentId:            $agent->id,
            customerId:         'cust-1',
            walletId:           'wallet-1',
            amount:             50000,
            currencyCode:       'BWP',
            journalId:          'float-journal-3',
            floatTransactionId: 'float-txn-dup',
            correlationId:      'corr-dup-1',
        ));

        $fireEvent();
        $fireEvent();

        $this->assertEquals(1, CommissionModel::where('idempotency_key', 'float-txn-dup:cash_in')->count());
    }
}
