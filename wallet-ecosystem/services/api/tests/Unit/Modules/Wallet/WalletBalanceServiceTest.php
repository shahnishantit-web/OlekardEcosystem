<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Wallet;

use App\Exceptions\ModuleNotImplementedException;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;
use App\Modules\Wallet\Application\Services\WalletBalanceService;
use App\Modules\Wallet\Domain\Enums\WalletStatus;
use App\Modules\Wallet\Domain\Exceptions\WalletNotFoundException;
use App\Modules\Wallet\Infrastructure\Models\CurrencyPocketModel;
use App\Modules\Wallet\Infrastructure\Models\WalletModel;
use App\Modules\Wallet\Infrastructure\Repositories\EloquentCurrencyPocketRepository;
use App\Modules\Wallet\Infrastructure\Repositories\EloquentWalletRepository;
use Illuminate\Database\Eloquent\Collection;
use Mockery;
use Tests\TestCase;

final class WalletBalanceServiceTest extends TestCase
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

    /** @test */
    public function balance_formula_is_ledger_minus_held(): void
    {
        config(['wallet.stub_ledger_balance' => false]);

        $holds   = new Collection([
            $this->makeHoldObject(50, 'h1'),
        ]);
        $wallet  = $this->makeWallet('wallet-1', WalletStatus::Active);
        $pocket  = $this->makePocketMock('BWP', 'ref-001', $holds);

        $this->walletRepo->shouldReceive('findById')->with('wallet-1')->andReturn($wallet);
        $this->pocketRepo->shouldReceive('findByWalletAndCurrency')->andReturn($pocket);
        $this->ledger->shouldReceive('getBalance')->with('ref-001')->andReturn(500);

        $result = $this->service->getBalance('wallet-1', 'BWP', 'corr-1');

        $this->assertSame(500, $result['ledger_balance']);
        $this->assertSame(50, $result['held_amount']);
        $this->assertSame(450, $result['available_balance']);
    }

    /** @test */
    public function available_balance_is_never_negative_when_held_exceeds_ledger(): void
    {
        config(['wallet.stub_ledger_balance' => false]);

        $holds  = new Collection([$this->makeHoldObject(80, 'h1')]);
        $wallet = $this->makeWallet('wallet-1', WalletStatus::Active);
        $pocket = $this->makePocketMock('BWP', 'ref-002', $holds);

        $this->walletRepo->shouldReceive('findById')->andReturn($wallet);
        $this->pocketRepo->shouldReceive('findByWalletAndCurrency')->andReturn($pocket);
        $this->ledger->shouldReceive('getBalance')->andReturn(40);

        $result = $this->service->getBalance('wallet-1', 'BWP', 'corr-1');

        $this->assertSame(0, $result['available_balance']);
    }

    /** @test */
    public function stub_ledger_mode_returns_zero_with_meta_flag(): void
    {
        config(['wallet.stub_ledger_balance' => true]);

        $wallet = $this->makeWallet('wallet-1', WalletStatus::Active);
        $pocket = $this->makePocketMock('BWP', 'ref-003', new Collection());

        $this->walletRepo->shouldReceive('findById')->andReturn($wallet);
        $this->pocketRepo->shouldReceive('findByWalletAndCurrency')->andReturn($pocket);
        $this->ledger->shouldReceive('getBalance')->andThrow(
            new ModuleNotImplementedException('Ledger', 'getBalance')
        );

        $result = $this->service->getBalance('wallet-1', 'BWP', 'corr-1');

        $this->assertSame(0, $result['ledger_balance']);
        $this->assertTrue($result['meta']['ledger_unavailable'] ?? false);
    }

    /** @test */
    public function production_mode_propagates_as_503_when_ledger_unavailable(): void
    {
        config(['wallet.stub_ledger_balance' => false]);

        $wallet = $this->makeWallet('wallet-1', WalletStatus::Active);
        $pocket = $this->makePocketMock('BWP', 'ref-004', new Collection());

        $this->walletRepo->shouldReceive('findById')->andReturn($wallet);
        $this->pocketRepo->shouldReceive('findByWalletAndCurrency')->andReturn($pocket);
        $this->ledger->shouldReceive('getBalance')->andThrow(
            new ModuleNotImplementedException('Ledger', 'getBalance')
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(503);

        $this->service->getBalance('wallet-1', 'BWP', 'corr-1');
    }

    /** @test */
    public function throws_wallet_not_found_for_missing_wallet(): void
    {
        $this->walletRepo->shouldReceive('findById')->andReturn(null);
        $this->expectException(WalletNotFoundException::class);
        $this->service->getBalance('missing', 'BWP', 'corr');
    }

    /** @test */
    public function as_of_timestamp_is_valid_rfc3339(): void
    {
        config(['wallet.stub_ledger_balance' => true]);

        $wallet = $this->makeWallet('wallet-1', WalletStatus::Active);
        $pocket = $this->makePocketMock('BWP', 'ref-005', new Collection());

        $this->walletRepo->shouldReceive('findById')->andReturn($wallet);
        $this->pocketRepo->shouldReceive('findByWalletAndCurrency')->andReturn($pocket);
        $this->ledger->shouldReceive('getBalance')->andThrow(
            new ModuleNotImplementedException('Ledger', 'getBalance')
        );

        $result = $this->service->getBalance('wallet-1', 'BWP', 'corr-1');

        $this->assertArrayHasKey('as_of_timestamp', $result);
        $parsed = \DateTimeImmutable::createFromFormat(\DateTime::RFC3339, $result['as_of_timestamp']);
        $this->assertNotFalse($parsed);
    }

    /** @test */
    public function pending_balance_is_always_zero_pre_e11(): void
    {
        config(['wallet.stub_ledger_balance' => true]);

        $wallet = $this->makeWallet('wallet-1', WalletStatus::Active);
        $pocket = $this->makePocketMock('BWP', 'ref-006', new Collection());

        $this->walletRepo->shouldReceive('findById')->andReturn($wallet);
        $this->pocketRepo->shouldReceive('findByWalletAndCurrency')->andReturn($pocket);
        $this->ledger->shouldReceive('getBalance')->andThrow(
            new ModuleNotImplementedException('Ledger', 'getBalance')
        );

        $result = $this->service->getBalance('wallet-1', 'BWP', 'corr-1');

        $this->assertArrayHasKey('pending_balance', $result);
        $this->assertSame(0, $result['pending_balance']);
    }

    private function makeWallet(string $id, WalletStatus $status): WalletModel
    {
        $wallet         = new WalletModel();
        $wallet->id     = $id;
        $wallet->status = $status;
        return $wallet;
    }

    private function makePocketMock(string $currencyCode, string $ledgerRef, Collection $holds): CurrencyPocketModel
    {
        $query  = Mockery::mock(\Illuminate\Database\Eloquent\Relations\HasMany::class);
        $query->shouldReceive('get')->andReturn($holds);

        $pocket = Mockery::mock(CurrencyPocketModel::class)->makePartial();
        $pocket->id                       = 'pocket-id';
        $pocket->currency_code            = $currencyCode;
        $pocket->ledger_account_reference = $ledgerRef;
        $pocket->shouldReceive('activeHolds')->andReturn($query);

        return $pocket;
    }

    private function makeHoldObject(int $amount, string $id): object
    {
        return new class ($amount, $id) {
            public function __construct(
                public readonly int $amount,
                public readonly string $id,
                public readonly ?\Carbon\Carbon $created_at = null,
                public readonly ?\Carbon\Carbon $expires_at = null,
                public readonly ?string $description = null,
            ) {}

            public function toIso8601String(): ?string { return null; }
        };
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
