<?php

declare(strict_types=1);

namespace App\Modules\Payment\Tests\Unit;

use App\Modules\Payment\Application\Services\EligibilityPipeline;
use App\Modules\Payment\Application\Services\ProviderRegistryService;
use App\Modules\Payment\Domain\Contracts\PaymentProviderRepositoryInterface;
use App\Modules\Payment\Domain\ValueObjects\EligibilityResult;
use App\Modules\Payment\Domain\ValueObjects\OptionsQuery;
use App\Modules\Payment\Domain\ValueObjects\PaymentProviderOption;
use App\Modules\Payment\Infrastructure\Models\PaymentProviderAccountModel;
use App\Modules\Payment\Infrastructure\Models\PaymentProviderModel;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

final class ProviderRegistryEligibilityTest extends TestCase
{
    /** @var PaymentProviderRepositoryInterface&MockObject */
    private PaymentProviderRepositoryInterface $repository;

    /** @var WalletModuleContract&MockObject */
    private WalletModuleContract $walletModule;

    private EligibilityPipeline $pipeline;

    private ProviderRegistryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository  = $this->createMock(PaymentProviderRepositoryInterface::class);
        $this->walletModule = $this->createMock(WalletModuleContract::class);
        $this->pipeline     = new EligibilityPipeline();

        $this->service = new ProviderRegistryService(
            $this->repository,
            $this->walletModule,
            $this->pipeline,
        );
    }

    private function makeQuery(): OptionsQuery
    {
        return new OptionsQuery(
            purposeType: 'bill', purposeReference: 'ref', currencyCode: 'BWP',
            amountMinor: 5000, organisationId: 'org-1', actorId: 'actor-1',
        );
    }

    private function makeAccount(string $adapterIdentifier = 'mock_bank'): PaymentProviderAccountModel
    {
        $provider                   = new PaymentProviderModel();
        $provider->code             = 'mock_bank';
        $provider->display_name     = 'Mock Bank';
        $provider->adapter_identifier = $adapterIdentifier;
        $provider->is_default       = false;
        $provider->sort_order       = 10;
        $provider->is_enabled = true;
        $provider->is_suspended = false;

        $account                          = new PaymentProviderAccountModel();
        $account->supported_payment_methods = ['bank_transfer'];
        $account->capability_flags        = [];
        $account->display_description     = null;
        $account->display_icon_key        = null;
        $account->display_badge           = null;
        $account->is_active = true;
        $account->supported_currencies = ['BWP'];
        $account->min_amount_minor = 0;
        $account->max_amount_minor = 100000;
        $account->setRelation('provider', $provider);

        return $account;
    }

    public function test_ineligible_account_excluded_from_results(): void
    {
        $account = $this->makeAccount();
        $this->repository->expects($this->once())
            ->method('findActiveForContext')
            ->willReturn([$account]);

        $account->supported_currencies = ['USD'];

        $options = $this->service->discoverOptions($this->makeQuery());

        self::assertCount(0, $options);
    }

    public function test_eligible_account_included_in_results(): void
    {
        $account = $this->makeAccount();
        $this->repository->expects($this->once())
            ->method('findActiveForContext')
            ->willReturn([$account]);


        $this->walletModule->expects($this->never())->method('findPrimaryWalletId');

        $options = $this->service->discoverOptions($this->makeQuery());

        self::assertCount(1, $options);
        self::assertInstanceOf(PaymentProviderOption::class, $options[0]);
        self::assertSame('mock_bank', $options[0]->providerCode);
    }

    public function test_response_includes_display_metadata(): void
    {
        $account = $this->makeAccount('olekard_wallet');
        $account->display_description = 'Pay from your wallet';
        $account->display_icon_key    = 'icon.payment.wallet';
        $account->display_badge       = 'RECOMMENDED';

        $this->repository->method('findActiveForContext')->willReturn([$account]);

        $options = $this->service->discoverOptions($this->makeQuery());

        self::assertSame('Pay from your wallet', $options[0]->displayDescription);
        self::assertSame('icon.payment.wallet', $options[0]->displayIconKey);
        self::assertSame('RECOMMENDED', $options[0]->displayBadge);
    }

    public function test_wallet_balance_check_runs_for_eligible_wallet_provider(): void
    {
        $account = $this->makeAccount('olekard_wallet');

        $this->repository->method('findActiveForContext')->willReturn([$account]);

        $this->walletModule->expects($this->once())
            ->method('findPrimaryWalletId')
            ->with('actor-1')
            ->willReturn('wallet-uuid-1');

        $this->walletModule->expects($this->once())
            ->method('availableBalance')
            ->with('wallet-uuid-1', 'BWP')
            ->willReturn(10_000);

        $options = $this->service->discoverOptions($this->makeQuery());

        self::assertTrue($options[0]->isWallet);
        self::assertSame(10_000, $options[0]->walletBalanceMinor);
        self::assertTrue($options[0]->hasSufficientBalance);
    }

    public function test_wallet_balance_check_skipped_for_non_wallet_provider(): void
    {
        $account = $this->makeAccount('mock_bank');

        $this->repository->method('findActiveForContext')->willReturn([$account]);

        $this->walletModule->expects($this->never())->method('findPrimaryWalletId');

        $options = $this->service->discoverOptions($this->makeQuery());

        self::assertFalse($options[0]->isWallet);
        self::assertNull($options[0]->walletBalanceMinor);
    }
}
