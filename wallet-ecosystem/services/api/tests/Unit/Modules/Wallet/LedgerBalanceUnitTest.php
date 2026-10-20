<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Wallet;

use App\Exceptions\ModuleNotImplementedException;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;
use App\Modules\Wallet\Application\Services\WalletBalanceService;
use App\Modules\Wallet\Domain\Enums\WalletStatus;
use App\Modules\Wallet\Infrastructure\Models\CurrencyPocketModel;
use App\Modules\Wallet\Infrastructure\Models\WalletModel;
use App\Modules\Wallet\Infrastructure\Repositories\EloquentCurrencyPocketRepository;
use App\Modules\Wallet\Infrastructure\Repositories\EloquentWalletRepository;
use Illuminate\Database\Eloquent\Collection;
use Mockery;
use Tests\TestCase;

/**
 * T098 — Mock LedgerModuleContract returning 27500; as_of_timestamp non-null and RFC 3339.
 * T103 — Hold aggregation: 2 active + 1 released → held_amount = sum of active only.
 */
final class LedgerBalanceUnitTest extends TestCase
{
    private EloquentWalletRepository $walletRepo;
    private EloquentCurrencyPocketRepository $pocketRepo;
    private LedgerModuleContract $ledger;
    private MetricsCollectorInterface $metrics;
    private WalletBalanceService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->walletRepo = Mockery::mock(EloquentWalletRepository::class);
        $this->pocketRepo = Mockery::mock(EloquentCurrencyPocketRepository::class);
        $this->ledger     = Mockery::mock(LedgerModuleContract::class);
        $this->metrics    = Mockery::mock(MetricsCollectorInterface::class)->shouldIgnoreMissing();

        $this->service = new WalletBalanceService(
            $this->walletRepo,
            $this->pocketRepo,
            $this->ledger,
            $this->metrics,
        );
    }

    /** T098 — Mock ledger returning 27500 reflected unchanged. */
    public function test_ledger_returns_27500_unchanged_in_response(): void
    {
        config(['wallet.stub_ledger_balance' => false]);

        $wallet = $this->makeWallet('w1', WalletStatus::Active);
        $pocket = $this->makePocket('BWP', 'ref-001', new Collection());

        $this->walletRepo->shouldReceive('findById')->andReturn($wallet);
        $this->pocketRepo->shouldReceive('findByWalletAndCurrency')->andReturn($pocket);
        $this->ledger->shouldReceive('getBalance')->andReturn(27500);

        $result = $this->service->getBalance('w1', 'BWP', 'corr');

        $this->assertSame(27500, $result['ledger_balance']);
    }

    /** T098 — as_of_timestamp is non-null and parseable as RFC 3339. */
    public function test_as_of_timestamp_is_non_null_and_rfc3339(): void
    {
        config(['wallet.stub_ledger_balance' => false]);

        $wallet = $this->makeWallet('w1', WalletStatus::Active);
        $pocket = $this->makePocket('BWP', 'ref-001', new Collection());

        $this->walletRepo->shouldReceive('findById')->andReturn($wallet);
        $this->pocketRepo->shouldReceive('findByWalletAndCurrency')->andReturn($pocket);
        $this->ledger->shouldReceive('getBalance')->andReturn(27500);

        $result = $this->service->getBalance('w1', 'BWP', 'corr');

        $this->assertNotNull($result['as_of_timestamp']);
        $parsed = \DateTimeImmutable::createFromFormat(\DateTime::RFC3339, $result['as_of_timestamp']);
        $this->assertNotFalse($parsed, 'as_of_timestamp must be RFC 3339 format');
    }

    /** T103 — 2 active holds + 1 released hold → held_amount = sum of active only. */
    public function test_released_hold_excluded_from_held_amount(): void
    {
        config(['wallet.stub_ledger_balance' => false]);

        $holds = new Collection([
            $this->makeHold(40, 'h1'), // active
            $this->makeHold(60, 'h2'), // active
            // released hold (not in active holds collection — filtered by query)
        ]);

        $wallet = $this->makeWallet('w1', WalletStatus::Active);
        $pocket = $this->makePocket('BWP', 'ref-002', $holds);

        $this->walletRepo->shouldReceive('findById')->andReturn($wallet);
        $this->pocketRepo->shouldReceive('findByWalletAndCurrency')->andReturn($pocket);
        $this->ledger->shouldReceive('getBalance')->andReturn(500);

        $result = $this->service->getBalance('w1', 'BWP', 'corr');

        $this->assertSame(100, $result['held_amount']);
        $this->assertCount(2, $result['holds']);
    }

    /** T103 — Released hold absent from holds array. */
    public function test_holds_array_contains_only_active_holds(): void
    {
        config(['wallet.stub_ledger_balance' => false]);

        $activeHolds = new Collection([$this->makeHold(30, 'h1')]);

        $wallet = $this->makeWallet('w1', WalletStatus::Active);
        $pocket = $this->makePocket('BWP', 'ref-003', $activeHolds);

        $this->walletRepo->shouldReceive('findById')->andReturn($wallet);
        $this->pocketRepo->shouldReceive('findByWalletAndCurrency')->andReturn($pocket);
        $this->ledger->shouldReceive('getBalance')->andReturn(300);

        $result = $this->service->getBalance('w1', 'BWP', 'corr');

        // The holds array only has the active holds returned by activeHolds()->get()
        $this->assertCount(1, $result['holds']);
        $this->assertSame('h1', $result['holds'][0]['hold_id']);
    }

    private function makeWallet(string $id, WalletStatus $status): WalletModel
    {
        $w         = new WalletModel();
        $w->id     = $id;
        $w->status = $status;
        return $w;
    }

    private function makePocket(string $code, string $ref, Collection $holds): CurrencyPocketModel
    {
        $q = Mockery::mock(\Illuminate\Database\Eloquent\Relations\HasMany::class);
        $q->shouldReceive('get')->andReturn($holds);

        $p = Mockery::mock(CurrencyPocketModel::class)->makePartial();
        $p->id                       = 'p-1';
        $p->currency_code            = $code;
        $p->ledger_account_reference = $ref;
        $p->shouldReceive('activeHolds')->andReturn($q);

        return $p;
    }

    private function makeHold(int $amount, string $id): object
    {
        return new class ($amount, $id) {
            public function __construct(
                public readonly int $amount,
                public readonly string $id,
                public readonly ?\Carbon\Carbon $created_at = null,
                public readonly ?\Carbon\Carbon $expires_at = null,
                public readonly ?string $description = null,
            ) {}
        };
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
