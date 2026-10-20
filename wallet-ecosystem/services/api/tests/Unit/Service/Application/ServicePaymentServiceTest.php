<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Application;

use App\Modules\Service\Application\Services\ServiceCatalogService;
use App\Modules\Service\Application\Services\ServiceFeeService;
use App\Modules\Service\Application\Services\ServicePaymentService;
use App\Modules\Service\Domain\Contracts\ServiceProviderAdapter;
use App\Modules\Service\Domain\DTO\PaymentSubmissionResult;
use App\Modules\Service\Domain\Enums\ServiceTransactionStatus;
use App\Modules\Service\Domain\Exceptions\InsufficientBalanceException;
use App\Modules\Service\Domain\Exceptions\StepUpRequiredException;
use App\Modules\Service\Infrastructure\Models\ServiceModel;
use App\Modules\Service\Infrastructure\Models\ServiceTransactionModel;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

final class ServicePaymentServiceTest extends TestCase
{
    use DatabaseTransactions;

    private ServicePaymentService $service;
    private ServiceProviderAdapter $adapter;
    private LedgerModuleContract $ledger;
    private WalletModuleContract $wallet;
    private ConfigurationServiceInterface $config;
    private AuditServiceInterface $audit;
    private EventPublisherInterface $events;
    private ServiceModel $activeService;
    private string $customerId;
    private string $walletId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adapter = $this->createMock(ServiceProviderAdapter::class);
        $this->ledger  = $this->createMock(LedgerModuleContract::class);
        $this->wallet  = $this->createMock(WalletModuleContract::class);
        $this->config  = $this->createMock(ConfigurationServiceInterface::class);
        $this->audit   = $this->createMock(AuditServiceInterface::class);
        $this->events  = $this->createMock(EventPublisherInterface::class);

        $this->config->method('getOrDefault')->willReturnCallback(function ($key, $default) {
            if ($key === 'auth.step_up_threshold') return 1000000;
            return $default;
        });

        $this->ledger->method('registerAccount');
        $this->audit->method('record');
        $this->events->method('publish');

        $this->service = new ServicePaymentService(
            new ServiceFeeService(),
            $this->adapter,
            $this->ledger,
            $this->wallet,
            $this->config,
            $this->audit,
            $this->events,
        );

        $catalogAudit  = $this->createMock(AuditServiceInterface::class);
        $catalogEvents = $this->createMock(EventPublisherInterface::class);
        $catalogAudit->method('record');
        $catalogEvents->method('publish');
        $catalog = new ServiceCatalogService($catalogAudit, $catalogEvents);

        $this->activeService = $catalog->create([
            'name'            => 'Test Service',
            'category'        => 'UTILITIES',
            'provider_code'   => 'TEST_PROV_'.uniqid(),
            'currency_code'   => 'BWP',
            'min_amount'      => 100,
            'max_amount'      => 1000000,
            'fee_type'        => 'flat',
            'fee_value'       => 200,
            'required_fields' => [['name' => 'meter', 'label' => 'Meter Number']],
        ], '00000000-0000-0000-0000-000000000001', 'corr-1');

        $this->customerId = (string)\Illuminate\Support\Str::orderedUuid();
        $this->walletId   = (string)\Illuminate\Support\Str::orderedUuid();
    }

    private function basePayload(string $idempotencyKey = null): array
    {
        return [
            'service_id'         => $this->activeService->id,
            'wallet_id'          => $this->walletId,
            'customer_reference' => '1234567890',
            'account_name'       => 'Test Account',
            'amount'             => 10000,
            'currency_code'      => 'BWP',
            'idempotency_key'    => $idempotencyKey ?? 'idem-'.uniqid(),
        ];
    }

    public function test_success_path_creates_transaction_and_posts_journal(): void
    {
        $this->wallet->method('availableBalance')->willReturn(100000);
        $this->wallet->method('getPocketLedgerAccountRef')->willReturn('wallet-pocket-ref-bwp');
        $this->ledger->method('postEntry')->willReturn('journal-id-123');
        $this->adapter->method('submitPayment')->willReturn(
            new PaymentSubmissionResult('MOCK-REF-123', 'completed')
        );

        $tx = $this->service->initiate($this->basePayload(), $this->customerId, 'corr-1');

        $this->assertSame(ServiceTransactionStatus::PendingProvider, $tx->status);
        $this->assertSame('MOCK-REF-123', $tx->provider_reference);
        $this->assertSame(10000, $tx->amount);
        $this->assertSame(200, $tx->fee_amount);
        $this->assertNotNull($tx->id);
    }

    public function test_insufficient_balance_leaves_initiated_record(): void
    {
        $this->wallet->method('availableBalance')->willReturn(50);
        $this->wallet->method('getPocketLedgerAccountRef')->willReturn('pocket-ref');
        $this->ledger->method('registerAccount');

        $idempotencyKey = 'idem-insuf-'.uniqid();

        try {
            $this->service->initiate($this->basePayload($idempotencyKey), $this->customerId, 'corr-insuf');
            $this->fail('Expected InsufficientBalanceException');
        } catch (InsufficientBalanceException) {
            // Expected
        }

        $record = ServiceTransactionModel::where('idempotency_key', $idempotencyKey)->first();
        $this->assertNotNull($record, 'Initiated record must exist even after balance failure');
        $this->assertSame(ServiceTransactionStatus::Initiated, $record->status);
    }

    public function test_idempotency_replay_returns_same_transaction(): void
    {
        $this->wallet->method('availableBalance')->willReturn(100000);
        $this->wallet->method('getPocketLedgerAccountRef')->willReturn('pocket-ref');
        $this->ledger->method('postEntry')->willReturn('journal-id');
        $this->adapter->method('submitPayment')->willReturn(
            new PaymentSubmissionResult('MOCK-REF', 'completed')
        );

        $payload = $this->basePayload('idem-replay-'.uniqid());

        $tx1 = $this->service->initiate($payload, $this->customerId, 'corr-1');
        $tx2 = $this->service->initiate($payload, $this->customerId, 'corr-2');

        $this->assertSame($tx1->id, $tx2->id);
        $this->assertCount(1, ServiceTransactionModel::where('idempotency_key', $payload['idempotency_key'])->get());
    }

    public function test_step_up_required_at_threshold(): void
    {
        $this->config = $this->createMock(ConfigurationServiceInterface::class);
        $this->config->method('getOrDefault')->willReturnCallback(function ($key, $default) {
            if ($key === 'auth.step_up_threshold') return 10200; // total = 10000 + 200 fee
            return $default;
        });

        $service = new ServicePaymentService(
            new ServiceFeeService(), $this->adapter, $this->ledger,
            $this->wallet, $this->config, $this->audit, $this->events,
        );

        $this->expectException(StepUpRequiredException::class);
        $service->initiate($this->basePayload(), $this->customerId, 'corr-1');
    }

    public function test_step_up_not_required_below_threshold(): void
    {
        $this->wallet->method('availableBalance')->willReturn(100000);
        $this->wallet->method('getPocketLedgerAccountRef')->willReturn('pocket-ref');
        $this->ledger->method('postEntry')->willReturn('journal-id');
        $this->adapter->method('submitPayment')->willReturn(
            new PaymentSubmissionResult('MOCK-REF', 'completed')
        );

        // threshold = 1000000, total = 10200 — no step-up needed
        $tx = $this->service->initiate($this->basePayload(), $this->customerId, 'corr-1');

        $this->assertNotNull($tx);
    }
}
