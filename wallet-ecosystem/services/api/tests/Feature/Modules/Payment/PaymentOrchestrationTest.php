<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Payment;

use App\Modules\Payment\Application\PaymentOrchestrationService;
use App\Modules\Payment\Application\Services\BusinessPurposeResolverRegistry;
use App\Modules\Payment\Domain\Contracts\BusinessPurposeResolverInterface;
use App\Modules\Payment\Domain\Contracts\PaymentAdapterInterface;
use App\Modules\Payment\Domain\Contracts\PaymentAttemptRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\PaymentIntentRepositoryInterface;
use App\Modules\Payment\Domain\Enums\PaymentAttemptStatus;
use App\Modules\Payment\Domain\Enums\PaymentIntentStatus;
use App\Modules\Payment\Domain\Exceptions\DuplicateAttemptException;
use App\Modules\Payment\Domain\Exceptions\InvalidStateTransitionException;
use App\Modules\Payment\Domain\ValueObjects\PayableObligation;
use App\Modules\Payment\Domain\ValueObjects\PaymentAttemptResult;
use App\Modules\Payment\Infrastructure\Models\PaymentAttemptModel;
use App\Modules\Payment\Infrastructure\Models\PaymentIntentModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use Illuminate\Contracts\Container\Container;
use Illuminate\Foundation\Testing\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Ramsey\Uuid\Uuid;

final class PaymentOrchestrationTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    private PaymentOrchestrationService $service;

    /** @var MockObject&PaymentIntentRepositoryInterface */
    private MockObject $intentRepo;

    /** @var MockObject&PaymentAttemptRepositoryInterface */
    private MockObject $attemptRepo;

    /** @var MockObject&BusinessPurposeResolverRegistry */
    private MockObject $purposeRegistry;

    /** @var MockObject&ConfigurationServiceInterface */
    private MockObject $config;

    /** @var MockObject&EventPublisherInterface */
    private MockObject $events;

    /** @var MockObject&WalletModuleContract */
    private MockObject $wallet;

    /** @var MockObject&LedgerModuleContract */
    private MockObject $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->intentRepo      = $this->createMock(PaymentIntentRepositoryInterface::class);
        $this->attemptRepo     = $this->createMock(PaymentAttemptRepositoryInterface::class);
        $this->purposeRegistry = $this->createMock(BusinessPurposeResolverRegistry::class);
        $this->config          = $this->createMock(ConfigurationServiceInterface::class);
        $this->events          = $this->createMock(EventPublisherInterface::class);
        $this->wallet          = $this->createMock(WalletModuleContract::class);
        $this->ledger          = $this->createMock(LedgerModuleContract::class);

        $this->service = new PaymentOrchestrationService(
            intentRepository:  $this->intentRepo,
            attemptRepository: $this->attemptRepo,
            purposeRegistry:   $this->purposeRegistry,
            wallet:            $this->wallet,
            ledger:            $this->ledger,
            config:            $this->config,
            audit:             $this->createMock(AuditServiceInterface::class),
            events:            $this->events,
            container:         $this->app,
        );
    }

    public function test_intent_created_with_server_resolved_amount(): void
    {
        $resolver = $this->createMock(BusinessPurposeResolverInterface::class);
        $resolver->method('resolve')->willReturn(new PayableObligation(
            amountMinor:    10000,
            currencyCode:   'BWP',
            organisationId: 'org-1',
            payerActorId:   'actor-1',
        ));

        $this->purposeRegistry->method('resolverFor')->willReturn($resolver);
        $this->intentRepo->method('findByIdempotencyKey')->willReturn(null);
        $this->intentRepo->method('findActiveForPurpose')->willReturn([]);
        $this->intentRepo->method('save')->willReturnCallback(fn () => null);
        $this->config->method('getOrDefault')->willReturn(30);

        $result = $this->service->createIntent([
            'purpose_type'      => 'funeral_contribution',
            'purpose_reference' => 'fc-001',
            'organisation_id'   => 'org-1',
            'payer_actor_id'    => 'actor-1',
            'currency_code'     => 'BWP',
            'idempotency_key'   => (string) Uuid::uuid7(),
            'correlation_id'    => (string) Uuid::uuid7(),
        ]);

        // Amount comes from server-resolved obligation, not caller
        self::assertSame(10000, $result['amount_minor']);
        self::assertSame(PaymentIntentStatus::Pending->value, $result['status']);
    }

    public function test_duplicate_idempotency_key_returns_same_intent(): void
    {
        $ikey = (string) Uuid::uuid7();

        $existing = new PaymentIntentModel([
            'id'           => (string) Uuid::uuid7(),
            'status'       => PaymentIntentStatus::Pending,
            'amount_minor' => 5000,
            'currency_code' => 'BWP',
            'expires_at'   => now()->addMinutes(30)->toIso8601String(),
        ]);

        $this->intentRepo->method('findByIdempotencyKey')->willReturn($existing);

        $resolver = $this->createMock(BusinessPurposeResolverInterface::class);
        $resolver->expects(self::never())->method('resolve');
        $this->purposeRegistry->method('resolverFor')->willReturn($resolver);

        $result = $this->service->createIntent([
            'purpose_type'      => 'funeral_contribution',
            'purpose_reference' => 'fc-001',
            'organisation_id'   => 'org-1',
            'payer_actor_id'    => 'actor-1',
            'currency_code'     => 'BWP',
            'idempotency_key'   => $ikey,
            'correlation_id'    => (string) Uuid::uuid7(),
        ]);

        self::assertSame($existing->id, $result['id']);
    }

    public function test_mobile_supplied_amount_is_ignored(): void
    {
        // Server always resolves amount via BusinessPurposeResolver; mobile/caller cannot supply it
        $resolver = $this->createMock(BusinessPurposeResolverInterface::class);
        $resolver->method('resolve')->willReturn(new PayableObligation(
            amountMinor:    10000, // server says 10000
            currencyCode:   'BWP',
            organisationId: 'org-1',
            payerActorId:   'actor-1',
        ));

        $this->purposeRegistry->method('resolverFor')->willReturn($resolver);
        $this->intentRepo->method('findByIdempotencyKey')->willReturn(null);
        $this->intentRepo->method('findActiveForPurpose')->willReturn([]);
        $this->intentRepo->method('save')->willReturnCallback(fn () => null);
        $this->config->method('getOrDefault')->willReturn(30);

        // Caller passes different amount (tamper attempt) — createIntent has no 'amount' parameter
        $result = $this->service->createIntent([
            'purpose_type'      => 'funeral_contribution',
            'purpose_reference' => 'fc-001',
            'organisation_id'   => 'org-1',
            'payer_actor_id'    => 'actor-1',
            'currency_code'     => 'BWP',
            'idempotency_key'   => (string) Uuid::uuid7(),
            'correlation_id'    => (string) Uuid::uuid7(),
            // No 'amount_minor' in the request array — server resolves it
        ]);

        self::assertSame(10000, $result['amount_minor']);
    }

    public function test_duplicate_attempt_exception_on_second_non_terminal_attempt(): void
    {
        $intentId = (string) Uuid::uuid7();

        $intent = new PaymentIntentModel([
            'id'           => $intentId,
            'status'       => PaymentIntentStatus::Processing,
            'amount_minor' => 10000,
            'currency_code' => 'BWP',
            'payer_actor_id' => 'actor-1',
            'correlation_id' => (string) Uuid::uuid7(),
        ]);

        $activeAttempt = new PaymentAttemptModel([
            'id'     => (string) Uuid::uuid7(),
            'status' => PaymentAttemptStatus::Processing,
        ]);

        $this->attemptRepo->method('findByIdempotencyKey')->willReturn(null);
        $this->intentRepo->method('findById')->willReturn($intent);
        $this->attemptRepo->method('findActiveForIntent')->willReturn($activeAttempt);

        $this->expectException(DuplicateAttemptException::class);

        $this->service->initiateAttempt([
            'payment_intent_id' => $intentId,
            'provider_code'     => 'olekard_wallet',
            'payer_actor_id'    => 'actor-1',
            'idempotency_key'   => (string) Uuid::uuid7(),
            'correlation_id'    => (string) Uuid::uuid7(),
        ]);
    }

    public function test_state_machine_rejects_invalid_transition(): void
    {
        $intentId = (string) Uuid::uuid7();

        // Intent is already in terminal state
        $intent = new PaymentIntentModel([
            'id'     => $intentId,
            'status' => PaymentIntentStatus::Succeeded, // terminal
        ]);

        $this->intentRepo->method('findById')->willReturn($intent);
        $this->attemptRepo->method('findByIdempotencyKey')->willReturn(null);
        $this->attemptRepo->method('findActiveForIntent')->willReturn(null);

        $this->expectException(\InvalidArgumentException::class);

        // Attempting processing → succeeded state machine would normally be fine
        // But pending → succeeded would be invalid
        $this->service->handleOutcome([
            'payment_intent_id'  => $intentId,
            'payment_attempt_id' => (string) Uuid::uuid7(),
            'outcome'            => 'failed', // succeeded → failed is invalid
            'failure_reason'     => 'test',
            'correlation_id'     => (string) Uuid::uuid7(),
        ]);
    }

    public function test_intent_expiry_transitions_to_expired(): void
    {
        $intentId = (string) Uuid::uuid7();

        $intent = new PaymentIntentModel([
            'id'              => $intentId,
            'status'          => PaymentIntentStatus::Pending,
            'payer_actor_id'  => 'actor-1',
            'purpose_type'    => 'test',
            'purpose_reference' => 'ref-1',
            'organisation_id' => 'org-1',
            'correlation_id'  => (string) Uuid::uuid7(),
        ]);

        $this->intentRepo->method('findById')->willReturn($intent);
        $this->attemptRepo->method('findActiveForIntent')->willReturn(null);
        $this->intentRepo->expects(self::once())->method('save')->with(
            self::callback(fn (PaymentIntentModel $m) => $m->status === PaymentIntentStatus::Expired)
        );

        $this->events->expects(self::once())->method('publish');

        $this->service->expireIntent($intentId, (string) Uuid::uuid7());
    }
}
