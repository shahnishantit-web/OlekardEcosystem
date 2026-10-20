<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Payment\Services;

use App\Modules\Payment\Application\Services\ProviderRegistryService;
use App\Modules\Payment\Domain\Contracts\PaymentProviderRepositoryInterface;
use App\Modules\Payment\Domain\ValueObjects\OptionsQuery;
use App\Modules\Payment\Infrastructure\Models\PaymentProviderAccountModel;
use App\Modules\Payment\Infrastructure\Models\PaymentProviderModel;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ProviderRegistryServiceTest extends TestCase
{
    private PaymentProviderRepositoryInterface&MockObject $repository;
    private WalletModuleContract&MockObject               $walletModule;
    private ProviderRegistryService                       $service;

    protected function setUp(): void
    {
        $this->repository   = $this->createMock(PaymentProviderRepositoryInterface::class);
        $this->walletModule = $this->createMock(WalletModuleContract::class);
        $this->service      = new ProviderRegistryService($this->repository, $this->walletModule);
    }

    private function makeQuery(int $amount = 5000): OptionsQuery
    {
        return new OptionsQuery(
            purposeType:      'test_purpose',
            purposeReference: 'ref-001',
            currencyCode:     'BWP',
            amountMinor:      $amount,
            organisationId:   'org-001',
            actorId:          'actor-001',
        );
    }

    private function makeWalletProvider(): PaymentProviderModel
    {
        $provider = new PaymentProviderModel();
        $provider->forceFill([
            'code'               => 'olekard_wallet',
            'display_name'       => 'OleKard Wallet',
            'adapter_identifier' => 'olekard_wallet',
            'is_enabled'         => true,
            'is_suspended'       => false,
            'is_default'         => true,
            'sort_order'         => 10,
            'environment'        => 'production',
        ]);
        return $provider;
    }

    private function makeAccount(PaymentProviderModel $provider): PaymentProviderAccountModel
    {
        $account = new PaymentProviderAccountModel();
        $account->forceFill([
            'id'                       => 'acc-001',
            'merchant_account_reference' => 'WALLET_MAIN',
            'supported_currencies'     => ['BWP', 'USD'],
            'supported_payment_methods' => ['wallet'],
            'min_amount_minor'         => 100,
            'max_amount_minor'         => 1000000,
            'enabled_organisations'    => [],
            'enabled_modules'          => [],
        ]);
        $account->setRelation('provider', $provider);
        return $account;
    }

    /** @test */
    public function active_wallet_option_is_returned(): void
    {
        $provider = $this->makeWalletProvider();
        $account  = $this->makeAccount($provider);

        $this->repository->method('findActiveForContext')->willReturn([$account]);
        $this->walletModule->method('findPrimaryWalletId')->willReturn('wallet-001');
        $this->walletModule->method('availableBalance')->willReturn(10000);

        $options = $this->service->discoverOptions($this->makeQuery());

        $this->assertCount(1, $options);
        $this->assertSame('olekard_wallet', $options[0]->providerCode);
        $this->assertTrue($options[0]->isWallet);
        $this->assertSame(10000, $options[0]->walletBalanceMinor);
        $this->assertTrue($options[0]->hasSufficientBalance);
    }

    /** @test */
    public function suspended_provider_is_absent_when_repository_filters_correctly(): void
    {
        // Repository already filters suspended — returning empty means no options
        $this->repository->method('findActiveForContext')->willReturn([]);
        $this->walletModule->expects($this->never())->method('findPrimaryWalletId');

        $options = $this->service->discoverOptions($this->makeQuery());

        $this->assertEmpty($options);
    }

    /** @test */
    public function wallet_insufficient_balance_shows_has_sufficient_balance_false(): void
    {
        $provider = $this->makeWalletProvider();
        $account  = $this->makeAccount($provider);

        $this->repository->method('findActiveForContext')->willReturn([$account]);
        $this->walletModule->method('findPrimaryWalletId')->willReturn('wallet-001');
        $this->walletModule->method('availableBalance')->willReturn(100); // less than query amount 5000

        $options = $this->service->discoverOptions($this->makeQuery(amountMinor: 5000));

        $this->assertCount(1, $options);
        $this->assertFalse($options[0]->hasSufficientBalance);
        $this->assertSame(100, $options[0]->walletBalanceMinor);
    }

    /** @test */
    public function no_wallet_found_shows_has_sufficient_balance_false(): void
    {
        $provider = $this->makeWalletProvider();
        $account  = $this->makeAccount($provider);

        $this->repository->method('findActiveForContext')->willReturn([$account]);
        $this->walletModule->method('findPrimaryWalletId')->willReturn(null);

        $options = $this->service->discoverOptions($this->makeQuery());

        $this->assertCount(1, $options);
        $this->assertFalse($options[0]->hasSufficientBalance);
        $this->assertNull($options[0]->walletBalanceMinor);
    }

    /** @test */
    public function non_wallet_provider_does_not_query_wallet(): void
    {
        $provider = new PaymentProviderModel();
        $provider->forceFill([
            'code'               => 'mock_bank',
            'display_name'       => 'Mock Bank',
            'adapter_identifier' => 'mock_bank',
            'is_enabled'         => true,
            'is_suspended'       => false,
            'is_default'         => false,
            'sort_order'         => 99,
            'environment'        => 'mock',
        ]);

        $account = new PaymentProviderAccountModel();
        $account->forceFill([
            'supported_currencies'     => ['BWP'],
            'supported_payment_methods' => ['redirect'],
            'min_amount_minor'         => 100,
            'max_amount_minor'         => 1000000,
            'enabled_organisations'    => [],
            'enabled_modules'          => [],
        ]);
        $account->setRelation('provider', $provider);

        $this->repository->method('findActiveForContext')->willReturn([$account]);
        $this->walletModule->expects($this->never())->method('findPrimaryWalletId');
        $this->walletModule->expects($this->never())->method('availableBalance');

        $options = $this->service->discoverOptions($this->makeQuery());

        $this->assertCount(1, $options);
        $this->assertFalse($options[0]->isWallet);
        $this->assertNull($options[0]->walletBalanceMinor);
        $this->assertNull($options[0]->hasSufficientBalance);
    }

    /** @test */
    public function multiple_providers_returned_in_repository_order(): void
    {
        $walletProvider = $this->makeWalletProvider();
        $walletAccount  = $this->makeAccount($walletProvider);

        $bankProvider = new PaymentProviderModel();
        $bankProvider->forceFill([
            'code'               => 'mock_bank',
            'display_name'       => 'Mock Bank',
            'adapter_identifier' => 'mock_bank',
            'is_enabled'         => true,
            'is_suspended'       => false,
            'is_default'         => false,
            'sort_order'         => 99,
            'environment'        => 'mock',
        ]);
        $bankAccount = new PaymentProviderAccountModel();
        $bankAccount->forceFill([
            'supported_currencies'     => ['BWP'],
            'supported_payment_methods' => ['redirect'],
            'min_amount_minor'         => 100,
            'max_amount_minor'         => 1000000,
            'enabled_organisations'    => [],
            'enabled_modules'          => [],
        ]);
        $bankAccount->setRelation('provider', $bankProvider);

        $this->repository->method('findActiveForContext')->willReturn([$walletAccount, $bankAccount]);
        $this->walletModule->method('findPrimaryWalletId')->willReturn('wallet-001');
        $this->walletModule->method('availableBalance')->willReturn(10000);

        $options = $this->service->discoverOptions($this->makeQuery());

        $this->assertCount(2, $options);
        $this->assertSame('olekard_wallet', $options[0]->providerCode);
        $this->assertSame('mock_bank', $options[1]->providerCode);
    }
}
