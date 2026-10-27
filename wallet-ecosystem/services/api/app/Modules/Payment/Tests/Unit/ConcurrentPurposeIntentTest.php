<?php

declare(strict_types=1);

namespace App\Modules\Payment\Tests\Unit;

use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Payment\Application\PaymentOrchestrationService;
use App\Modules\Payment\Application\Services\BusinessPurposeResolverRegistry;
use App\Modules\Payment\Domain\Contracts\BusinessPurposeResolverInterface;
use App\Modules\Payment\Domain\Contracts\PaymentAttemptRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\PaymentIntentRepositoryInterface;
use App\Modules\Payment\Domain\Enums\PaymentIntentStatus;
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
 * Constitution P4: concurrency and duplicate-processing guard for createIntent().
 *
 * Tests that two racing createIntent() calls for the same purpose_type + purpose_reference
 * result in at most one intent record, via:
 * (a) Idempotency-key guard  — same key + payer returns existing, resolve() never called
 * (b) Active-purpose guard   — findActiveForPurpose() returns existing, save() never called
 */
final class ConcurrentPurposeIntentTest extends TestCase
{
    /** @var MockObject&PaymentIntentRepositoryInterface */
    private MockObject $intentRepo;
    /** @var MockObject&AuditServiceInterface */
    private MockObject $audit;
    /** @var MockObject&ConfigurationServiceInterface */
    private MockObject $config;
    /** @var MockObject&BusinessPurposeResolverInterface */
    private MockObject $resolver;

    private PaymentOrchestrationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->intentRepo = $this->createMock(PaymentIntentRepositoryInterface::class);
        $this->audit      = $this->createMock(AuditServiceInterface::class);
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
            audit:             $this->audit,
            events:            $this->createMock(EventPublisherInterface::class),
            container:         $this->createMock(Container::class),
        );
    }

    private function makeExistingIntent(string $id = 'intent-existing'): PaymentIntentModel
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
        $intent->idempotency_key   = 'idem-first';
        $intent->correlation_id    = 'corr-001';
        $intent->expires_at        = now()->addMinutes(30);
        $intent->version           = 1;
        return $intent;
    }

    private function makeObligation(): EnrichedPayableObligation
    {
        return new EnrichedPayableObligation(
            amountMinor:    10000,
            currencyCode:   'BWP',
            organisationId: 'org-001',
            payerActorId:   'actor-001',
        );
    }

    /**
     * (a) Idempotency-key guard: same idempotency key + payer returns the existing intent.
     * resolve() must NOT be called — we replay before touching the domain layer.
     */
    public function test_idempotency_key_guard_returns_existing_without_resolution(): void
    {
        $existing = $this->makeExistingIntent();

        $this->intentRepo->method('findByIdempotencyKey')->willReturn($existing);
        $this->intentRepo->expects($this->never())->method('save');
        $this->resolver->expects($this->never())->method('resolve');

        $result = $this->service->createIntent([
            'purpose_type'      => 'bill',
            'purpose_reference' => 'ref-001',
            'organisation_id'   => 'org-001',
            'payer_actor_id'    => 'actor-001',
            'currency_code'     => 'BWP',
            'idempotency_key'   => 'idem-first', // same as existing
            'correlation_id'    => 'corr-replay',
        ]);

        $this->assertSame('intent-existing', $result['id']);
    }

    /**
     * (b) Active-purpose guard: second concurrent call with a different idempotency key
     * finds the existing active intent via findActiveForPurpose() and returns it
     * without persisting a new intent or creating a duplicate quote row.
     */
    public function test_active_purpose_guard_returns_existing_without_creating_duplicate(): void
    {
        $existing = $this->makeExistingIntent();

        // Idempotency check: different key, so null (not a replay)
        $this->intentRepo->method('findByIdempotencyKey')->willReturn(null);

        // resolve() IS called (before the purpose guard) — obligation returned normally
        $this->resolver->method('resolve')->willReturn($this->makeObligation());

        // findActiveForPurpose returns the existing intent (simulates concurrent first-write wins)
        $this->intentRepo->method('findActiveForPurpose')->willReturn([$existing]);

        // save() must NEVER be called — the second call exits early
        $this->intentRepo->expects($this->never())->method('save');

        $result = $this->service->createIntent([
            'purpose_type'      => 'bill',
            'purpose_reference' => 'ref-001',
            'organisation_id'   => 'org-001',
            'payer_actor_id'    => 'actor-001',
            'currency_code'     => 'BWP',
            'idempotency_key'   => 'idem-second', // different key — concurrent caller
            'correlation_id'    => 'corr-concurrent',
        ]);

        // Second caller receives the existing intent — no duplicate persisted
        $this->assertSame('intent-existing', $result['id']);
    }

    /**
     * Verifies resolve() is called once per genuine first-time createIntent —
     * not zero times (guard fires too early) and not twice (duplicate resolution).
     * This test exits at the purpose guard, so DB::transaction is never reached.
     */
    public function test_resolver_called_exactly_once_per_genuine_request(): void
    {
        $existing = $this->makeExistingIntent();

        $this->intentRepo->method('findByIdempotencyKey')->willReturn(null);
        $this->intentRepo->method('findActiveForPurpose')->willReturn([$existing]);

        // resolve() should be called exactly once — not zero (early guard), not twice
        $this->resolver->expects($this->once())->method('resolve')->willReturn($this->makeObligation());

        $this->service->createIntent([
            'purpose_type'      => 'bill',
            'purpose_reference' => 'ref-001',
            'organisation_id'   => 'org-001',
            'payer_actor_id'    => 'actor-001',
            'currency_code'     => 'BWP',
            'idempotency_key'   => 'idem-third',
            'correlation_id'    => 'corr-once',
        ]);
    }
}
