<?php

declare(strict_types=1);

namespace App\Modules\Payment\Tests\Unit;

use App\Modules\Payment\Domain\Exceptions\PurposeNotPayableException;
use App\Modules\Payment\Domain\ValueObjects\EnrichedPayableObligation;
use Tests\TestCase;

final class EnrichedObligationValidationTest extends TestCase
{
    private function makeObligation(
        ?\DateTimeImmutable $expiresAt = null,
        string $payerActorId = 'actor-001',
    ): EnrichedPayableObligation {
        return new EnrichedPayableObligation(
            amountMinor:    10000,
            currencyCode:   'BWP',
            organisationId: 'org-001',
            payerActorId:   $payerActorId,
            expiresAt:      $expiresAt,
        );
    }

    public function test_null_expires_at_is_accepted(): void
    {
        $obligation = $this->makeObligation(expiresAt: null);
        $this->assertNull($obligation->expiresAt);
    }

    public function test_future_expires_at_is_accepted(): void
    {
        $obligation = $this->makeObligation(
            expiresAt: new \DateTimeImmutable('+1 hour')
        );
        $this->assertInstanceOf(\DateTimeImmutable::class, $obligation->expiresAt);
    }

    public function test_expired_obligation_raises_exception_in_orchestrator(): void
    {
        $obligation = $this->makeObligation(
            expiresAt: new \DateTimeImmutable('-1 second')
        );

        $this->expectException(PurposeNotPayableException::class);
        $this->expectExceptionMessageMatches('/expired/i');

        $this->simulateValidation($obligation, 'actor-001');
    }

    public function test_ownership_mismatch_raises_exception(): void
    {
        $obligation = $this->makeObligation(payerActorId: 'actor-999');

        $this->expectException(PurposeNotPayableException::class);
        $this->expectExceptionMessageMatches('/own/i');

        $this->simulateValidation($obligation, 'actor-001');
    }

    public function test_matching_actor_and_no_expiry_passes(): void
    {
        $obligation = $this->makeObligation(expiresAt: null, payerActorId: 'actor-001');

        $result = $this->simulateValidation($obligation, 'actor-001');
        $this->assertSame(10000, $result['amount_minor']);
    }

    public function test_amount_minor_must_be_positive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EnrichedPayableObligation(
            amountMinor:    0,
            currencyCode:   'BWP',
            organisationId: 'org-001',
            payerActorId:   'actor-001',
        );
    }

    /** Exercise the real orchestrator instead of duplicating its validation. */
    private function simulateValidation(EnrichedPayableObligation $obligation, string $requestingActorId): array
    {
        $resolver = $this->createMock(\App\Modules\Payment\Domain\Contracts\BusinessPurposeResolverInterface::class);
        $resolver->method('resolve')->willReturn($obligation);
        $registry = new \App\Modules\Payment\Application\Services\BusinessPurposeResolverRegistry();
        $registry->register('bill', $resolver);
        $intents = $this->createMock(\App\Modules\Payment\Domain\Contracts\PaymentIntentRepositoryInterface::class);
        $intents->method('findActiveForPurpose')->willReturn([]);
        $config = $this->createMock(\App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface::class);
        $config->method('getOrDefault')->willReturnCallback(static fn ($key, $default) => $default);
        $service = new \App\Modules\Payment\Application\PaymentOrchestrationService(
            $intents,
            $this->createMock(\App\Modules\Payment\Domain\Contracts\PaymentAttemptRepositoryInterface::class),
            $registry,
            $this->createMock(\App\Modules\Wallet\Domain\Contracts\WalletModuleContract::class),
            $this->createMock(\App\Modules\Ledger\Domain\Contracts\LedgerModuleContract::class),
            $config,
            $this->createMock(\App\Modules\Platform\Domain\Contracts\AuditServiceInterface::class),
            $this->createMock(\App\Modules\Platform\Domain\Contracts\EventPublisherInterface::class),
            $this->app,
        );
        return $service->createIntent(['purpose_type' => 'bill', 'purpose_reference' => 'ref-001',
            'organisation_id' => $obligation->organisationId, 'payer_actor_id' => $requestingActorId,
            'currency_code' => 'BWP', 'idempotency_key' => 'test-only', 'correlation_id' => 'test-only']);
    }
}

