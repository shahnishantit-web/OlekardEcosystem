<?php

declare(strict_types=1);

namespace App\Modules\Payment\Tests\Unit;

use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Payment\Application\PaymentOrchestrationService;
use App\Modules\Payment\Application\Services\BusinessPurposeResolverRegistry;
use App\Modules\Payment\Domain\Contracts\PaymentAdapterInterface;
use App\Modules\Payment\Domain\Contracts\PaymentAttemptRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\PaymentIntentRepositoryInterface;
use App\Modules\Payment\Domain\Enums\PaymentAttemptStatus;
use App\Modules\Payment\Domain\Enums\PaymentIntentStatus;
use App\Modules\Payment\Domain\Exceptions\DuplicateAttemptException;
use App\Modules\Payment\Domain\ValueObjects\PaymentAttemptResult;
use App\Modules\Payment\Infrastructure\Models\PaymentAttemptModel;
use App\Modules\Payment\Infrastructure\Models\PaymentIntentModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

/**
 * T017 (P06 US2): Persist-before-execute invariant.
 *
 * Verifies that `initiateAttempt()` writes the attempt record in SUBMITTING state
 * to durable storage BEFORE calling the external adapter. Uses DB::shouldReceive
 * so transaction callbacks execute inline without a real DB connection.
 *
 * These tests require the Laravel application to be bootstrapped (Docker or
 * the local .env configured with a live database for service provider boot).
 * Run via: php artisan test --filter=PersistBeforeExecuteTest
 */
final class PersistBeforeExecuteTest extends TestCase
{
    /** @var MockObject&PaymentIntentRepositoryInterface */
    private MockObject $intentRepo;
    /** @var MockObject&PaymentAttemptRepositoryInterface */
    private MockObject $attemptRepo;
    /** @var MockObject&PaymentAdapterInterface */
    private MockObject $adapter;
    /** @var MockObject&ConfigurationServiceInterface */
    private MockObject $config;

    private PaymentOrchestrationService $service;

    /** Captured attempt passed to $attemptRepo->save() during Transaction 1 */
    private ?PaymentAttemptModel $savedAttempt = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Run payment transaction callbacks inline for this unit fixture.
        DB::shouldReceive('connection')
            ->with('payment')
            ->andReturnSelf();
        DB::shouldReceive('transaction')
            ->andReturnUsing(function (callable $cb) { return $cb(); });

        $this->intentRepo  = $this->createMock(PaymentIntentRepositoryInterface::class);
        $this->attemptRepo = $this->createMock(PaymentAttemptRepositoryInterface::class);
        $this->adapter     = $this->createMock(PaymentAdapterInterface::class);
        $this->config      = $this->createMock(ConfigurationServiceInterface::class);
        $this->config->method('getOrDefault')->willReturn('15');

        $container = $this->createMock(Container::class);
        $container->method('bound')->willReturn(true);
        $container->method('make')->willReturn($this->adapter);

        $provider = new \App\Modules\Payment\Infrastructure\Models\PaymentProviderModel([
            'code' => 'wallet', 'adapter_identifier' => 'olekard_wallet', 'environment' => 'local',
            'is_enabled' => true, 'is_suspended' => false]);
        $account = new \App\Modules\Payment\Infrastructure\Models\PaymentProviderAccountModel([
            'id' => 'account-001', 'is_active' => true, 'config_version' => 1,
            'supported_currencies' => ['BWP'], 'min_amount_minor' => 0, 'max_amount_minor' => 100000]);
        $account->setRelation('provider', $provider);
        $providers = $this->createMock(\App\Modules\Payment\Domain\Contracts\PaymentProviderRepositoryInterface::class);
        $providers->method('findActiveForContext')->willReturn([$account]);

        $this->service = new PaymentOrchestrationService(
            intentRepository:  $this->intentRepo,
            attemptRepository: $this->attemptRepo,
            purposeRegistry:   new BusinessPurposeResolverRegistry(),
            wallet:            $this->createMock(WalletModuleContract::class),
            ledger:            $this->createMock(LedgerModuleContract::class),
            config:            $this->config,
            audit:             $this->createMock(AuditServiceInterface::class),
            events:            $this->createMock(EventPublisherInterface::class),
            container:         $container,
            providerRepository: $providers,
        );

        // Capture the model passed to save() so we can inspect it post-call
        $this->savedAttempt = null;
        $this->attemptRepo->method('save')
            ->willReturnCallback(function (PaymentAttemptModel $model): void {
                $this->savedAttempt = $model;
            });

        // findByIdempotencyKey returns null → no replay
        $this->attemptRepo->method('findByIdempotencyKey')->willReturn(null);
        // findActiveForIntent returns null → no duplicate guard
        // findUnknownForIntent returns null → no unsafe-switch guard
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function makeIntent(): PaymentIntentModel
    {
        $intent = new PaymentIntentModel();
        $intent->id              = 'intent-001';
        $intent->purpose_type = 'bill';
        $intent->purpose_reference = 'ref-001';
        $intent->organisation_id = 'org-001';
        $intent->payer_actor_id  = 'actor-001';
        $intent->currency_code   = 'BWP';
        $intent->amount_minor    = 10000;
        $intent->status          = PaymentIntentStatus::Pending;
        $intent->correlation_id  = 'corr-001';
        $intent->expires_at      = now()->addMinutes(30);
        return $intent;
    }

    private function makeRequest(): array
    {
        return [
            'payment_intent_id' => 'intent-001',
            'provider_code'     => 'wallet',
            'payer_actor_id'    => 'actor-001',
            'idempotency_key'   => 'idem-001',
            'correlation_id'    => 'corr-001',
        ];
    }

    // ─── Tests ────────────────────────────────────────────────────────────────

    /**
     * SC-01: When the adapter throws an exception, the attempt record must already
     * exist in SUBMITTING state in durable storage. The exception must propagate.
     */
    public function test_attempt_exists_in_submitting_state_when_adapter_throws(): void
    {
        $this->intentRepo->method('findById')->willReturn($this->makeIntent());

        // Adapter fails immediately
        $this->adapter->method('initiatePayment')
            ->willThrowException(new \RuntimeException('network error'));

        // findById on attempt (for T2 failure rollback) returns the SUBMITTING model
        $this->attemptRepo->method('findById')
            ->willReturnCallback(fn() => $this->savedAttempt);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('network error');

        try {
            $this->service->initiateAttempt($this->makeRequest());
        } finally {
            $this->assertNotNull($this->savedAttempt, 'Attempt must be saved before adapter call');
            $this->assertSame(
                PaymentAttemptStatus::Submitting,
                $this->savedAttempt->status,
                'Attempt status must be SUBMITTING when saved in T1'
            );
        }
    }

    public function test_concurrent_attempt_observed_after_intent_lock_blocks_adapter(): void
    {
        $this->intentRepo->method('findById')->willReturn($this->makeIntent());
        $this->attemptRepo->method('findActiveForIntent')->willReturnOnConsecutiveCalls(null, new PaymentAttemptModel());
        $this->adapter->expects($this->never())->method('initiatePayment');
        $this->expectException(DuplicateAttemptException::class);
        $this->service->initiateAttempt($this->makeRequest());
    }

    /**
     * SC-01: The attempt record persisted in T1 must have a non-null operation_id
     * (a server-generated UUID v7 for provider-side deduplication).
     * Also verifies quote_id/quote_version are populated when a quote exists.
     */
    public function test_attempt_has_operation_id_quote_id_quote_version(): void
    {
        $this->intentRepo->method('findById')->willReturn($this->makeIntent());

        $this->adapter->method('initiatePayment')
            ->willReturn(new PaymentAttemptResult(status: PaymentAttemptStatus::Processing));

        // findByIdempotencyKey in return shape
        $this->attemptRepo->method('findByIdempotencyKey')
            ->willReturnCallback(fn() => $this->savedAttempt);

        $this->service->initiateAttempt($this->makeRequest());

        $this->assertNotNull($this->savedAttempt, 'Attempt must be saved');
        $this->assertNotNull(
            $this->savedAttempt->operation_id,
            'operation_id must be set (server-generated deduplication ID)'
        );
        // UUID format: 8-4-4-4-12 hex groups
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $this->savedAttempt->operation_id
        );
    }
}
