<?php

declare(strict_types=1);

namespace App\Modules\Payment\Tests\Unit;

use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Payment\Application\PaymentOrchestrationService;
use App\Modules\Payment\Application\Services\BusinessPurposeResolverRegistry;
use App\Modules\Payment\Domain\Contracts\BusinessPurposeResolverInterface;
use App\Modules\Payment\Domain\Contracts\PaymentAttemptRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\PaymentIntentRepositoryInterface;
use App\Modules\Payment\Domain\Enums\PaymentAccountingStatus;
use App\Modules\Payment\Domain\Enums\PaymentFulfilmentStatus;
use App\Modules\Payment\Domain\Enums\PaymentIntentStatus;
use App\Modules\Payment\Domain\Exceptions\PurposeNotPayableException;
use App\Modules\Payment\Domain\ValueObjects\EnrichedPayableObligation;
use App\Modules\Payment\Infrastructure\Models\PaymentIntentModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use Illuminate\Contracts\Container\Container;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

/**
 * T013 (P06 US1): Intent creation idempotency and obligation validation.
 *
 * All paths exit before the DB::transaction block so no real connection is needed.
 * The spec refers to the initial state as "CREATED"; the codebase uses PaymentIntentStatus::Pending.
 */
final class CreateIntentIdempotencyTest extends TestCase
{
    /** @var MockObject&PaymentIntentRepositoryInterface */
    private MockObject $intentRepo;
    /** @var MockObject&ConfigurationServiceInterface */
    private MockObject $config;
    /** @var MockObject&BusinessPurposeResolverInterface */
    private MockObject $resolver;

    private PaymentOrchestrationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->intentRepo = $this->createMock(PaymentIntentRepositoryInterface::class);
        $this->config     = $this->createMock(ConfigurationServiceInterface::class);
        $this->config->method('getOrDefault')->willReturn('30');

        $this->resolver = $this->createMock(BusinessPurposeResolverInterface::class);
        $registry       = new BusinessPurposeResolverRegistry();
        $registry->register('bill', $this->resolver);

        $this->service = new PaymentOrchestrationService(
            intentRepository:  $this->intentRepo,
            attemptRepository: $this->createMock(PaymentAttemptRepositoryInterface::class),
            purposeRegistry:   $registry,
            wallet:            $this->createMock(WalletModuleContract::class),
            ledger:            $this->createMock(LedgerModuleContract::class),
            config:            $this->config,
            audit:             $this->createMock(AuditServiceInterface::class),
            events:            $this->createMock(EventPublisherInterface::class),
            container:         $this->createMock(Container::class),
        );
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function makeIntent(string $id = 'intent-001', string $idempotencyKey = 'idem-a'): PaymentIntentModel
    {
        $intent = new PaymentIntentModel();
        $intent->id                = $id;
        $intent->purpose_type      = 'bill';
        $intent->purpose_reference = 'ref-001';
        $intent->organisation_id   = 'org-001';
        $intent->payer_actor_id    = 'actor-001';
        $intent->currency_code     = 'BWP';
        $intent->amount_minor      = 10000;
        $intent->status            = PaymentIntentStatus::Pending;
        $intent->accounting_status = PaymentAccountingStatus::PendingLedger;
        $intent->fulfilment_status = PaymentFulfilmentStatus::Pending;
        $intent->idempotency_key   = $idempotencyKey;
        $intent->correlation_id    = 'corr-001';
        $intent->expires_at        = now()->addMinutes(30);
        return $intent;
    }

    private function makeRequest(string $idempotencyKey = 'idem-a'): array
    {
        return [
            'purpose_type'      => 'bill',
            'purpose_reference' => 'ref-001',
            'organisation_id'   => 'org-001',
            'payer_actor_id'    => 'actor-001',
            'currency_code'     => 'BWP',
            'idempotency_key'   => $idempotencyKey,
            'correlation_id'    => 'corr-001',
        ];
    }

    // ─── Tests ────────────────────────────────────────────────────────────────

    /**
     * SC-01: Calling createIntent() twice with the same idempotency key + payer
     * returns the same intent ID. The domain resolver must NOT be called — replay
     * returns immediately before any obligation resolution.
     */
    public function test_same_idempotency_key_returns_same_intent(): void
    {
        $existing = $this->makeIntent('intent-001', 'idem-a');

        $this->intentRepo->method('findByIdempotencyKey')->with('idem-a', 'actor-001')->willReturn($existing);
        $this->resolver->expects($this->never())->method('resolve');
        $this->intentRepo->expects($this->never())->method('save');

        $result = $this->service->createIntent($this->makeRequest('idem-a'));

        $this->assertSame('intent-001', $result['id']);
        $this->assertSame(PaymentIntentStatus::Pending->value, $result['status']);
        $this->assertSame(PaymentAccountingStatus::PendingLedger->value, $result['accounting_status']);
        $this->assertSame(PaymentFulfilmentStatus::Pending->value, $result['fulfilment_status']);
    }

    /**
     * SC-01 (resume): A second caller with a different idempotency key for the same
     * purpose_reference + organisation resumes the existing active intent rather than
     * creating a duplicate. No new intent is persisted.
     */
    public function test_resume_returns_existing_active_intent(): void
    {
        $existing = $this->makeIntent('intent-001', 'idem-a');

        // Key B is not in the idempotency index
        $this->intentRepo->method('findByIdempotencyKey')->willReturn(null);

        // Obligation resolves normally
        $this->resolver->method('resolve')->willReturn(new EnrichedPayableObligation(
            amountMinor:    10000,
            currencyCode:   'BWP',
            organisationId: 'org-001',
            payerActorId:   'actor-001',
        ));

        // findActiveForPurpose returns the existing intent from the same org
        $this->intentRepo->method('findActiveForPurpose')->willReturn([$existing]);

        // No save — the active-purpose guard exits early
        $this->intentRepo->expects($this->never())->method('save');

        $result = $this->service->createIntent($this->makeRequest('idem-b'));

        $this->assertSame('intent-001', $result['id']);
    }

    /**
     * Resolver returns an obligation whose expiresAt is in the past.
     * createIntent() must throw PurposeNotPayableException before any DB write.
     */
    public function test_expired_purpose_throws_purpose_not_payable(): void
    {
        $this->intentRepo->method('findByIdempotencyKey')->willReturn(null);

        $this->resolver->method('resolve')->willReturn(new EnrichedPayableObligation(
            amountMinor:    10000,
            currencyCode:   'BWP',
            organisationId: 'org-001',
            payerActorId:   'actor-001',
            expiresAt:      new \DateTimeImmutable('-1 second'),
        ));

        $this->intentRepo->expects($this->never())->method('save');

        $this->expectException(PurposeNotPayableException::class);
        $this->expectExceptionMessageMatches('/expired/i');

        $this->service->createIntent($this->makeRequest('idem-expired'));
    }

    /**
     * Resolver returns an obligation owned by a different actor.
     * createIntent() must throw PurposeNotPayableException — the requesting actor
     * does not own this purpose.
     */
    public function test_wrong_actor_throws_purpose_not_payable(): void
    {
        $this->intentRepo->method('findByIdempotencyKey')->willReturn(null);

        // payerActorId on the obligation is different from the request's payer_actor_id
        $this->resolver->method('resolve')->willReturn(new EnrichedPayableObligation(
            amountMinor:    10000,
            currencyCode:   'BWP',
            organisationId: 'org-001',
            payerActorId:   'actor-different',
        ));

        $this->intentRepo->expects($this->never())->method('save');

        $this->expectException(PurposeNotPayableException::class);
        $this->expectExceptionMessageMatches('/own/i');

        $this->service->createIntent($this->makeRequest('idem-wrong-actor'));
    }
}
