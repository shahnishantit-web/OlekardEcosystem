<?php

declare(strict_types=1);

namespace Tests\Unit\Qa;

use App\Modules\Qa\Application\Executors\WalletBalanceExecutor;
use App\Modules\Qa\Domain\Enums\QaCategory;
use App\Modules\Qa\Domain\Enums\TestRunStatus;
use App\Modules\Qa\Models\TestRun;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class WalletBalanceExecutorTest extends TestCase
{
    use DatabaseTransactions;

    private TestRun $run;

    protected function setUp(): void
    {
        parent::setUp();
        $this->run = $this->createTestRun(QaCategory::WalletBalance);
    }

    public function test_executor_writes_results_for_all_active_cases(): void
    {
        $caseCount = DB::connection('platform')
            ->table('qa.test_cases')
            ->where('category', QaCategory::WalletBalance->value)
            ->where('is_active', true)
            ->count();

        $executor = new WalletBalanceExecutor();
        $executor->execute($this->run);

        $resultCount = DB::connection('platform')
            ->table('qa.test_results')
            ->where('test_run_id', $this->run->id)
            ->count();

        $this->assertEquals($caseCount, $resultCount);
    }

    public function test_no_wallet_balance_committed_after_run(): void
    {
        $walletTable = 'wallet.wallets';
        $balanceBefore = DB::connection('platform')
            ->table($walletTable)
            ->where('id', 'qa-test-wallet-id')
            ->value('balance_minor_units');

        $executor = new WalletBalanceExecutor();
        $executor->execute($this->run);

        $balanceAfter = DB::connection('platform')
            ->table($walletTable)
            ->where('id', 'qa-test-wallet-id')
            ->value('balance_minor_units');

        $this->assertEquals($balanceBefore, $balanceAfter, 'Wallet balance must not be committed by executor');
    }

    public function test_executor_does_not_throw(): void
    {
        $executor = new WalletBalanceExecutor();

        $this->expectNotToPerformAssertions();
        $executor->execute($this->run);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function createTestRun(QaCategory $category): TestRun
    {
        $id = (string) Uuid::uuid7();
        DB::connection('platform')->table('qa.test_runs')->insert([
            'id'              => $id,
            'category'        => $category->value,
            'status'          => TestRunStatus::Running->value,
            'triggered_by'    => (string) Uuid::uuid7(),
            'idempotency_key' => 'wallet-test-' . Uuid::uuid7()->toString(),
            'correlation_id'  => (string) Uuid::uuid7(),
            'created_at'      => now(),
            'started_at'      => now(),
        ]);

        return TestRun::on('platform')->findOrFail($id);
    }
}
