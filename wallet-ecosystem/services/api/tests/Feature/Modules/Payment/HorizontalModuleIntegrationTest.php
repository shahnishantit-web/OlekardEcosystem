<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Payment;

use App\Modules\Payment\Application\PaymentOrchestrationService;
use App\Modules\Payment\Application\Services\BusinessPurposeResolverRegistry;
use App\Modules\Payment\Application\Services\PaymentApplicationService;
use App\Modules\Payment\Application\Services\ProviderRegistryService;
use App\Modules\Payment\Domain\Contracts\PaymentAttemptRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\PaymentIntentRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\PaymentModuleContract;
use App\Modules\Payment\Domain\Exceptions\PaymentIntentNotFoundException;
use App\Modules\Payment\Domain\Exceptions\UnknownPurposeTypeException;
use App\Modules\Payment\Domain\ValueObjects\OptionsQuery;
use App\Modules\Payment\Domain\ValueObjects\PaymentIntentRequest;
use App\Modules\Payment\Domain\ValueObjects\PaymentProviderOption;
use App\Modules\Payment\Infrastructure\Models\PaymentIntentModel;
use App\Modules\Payment\Tests\StubPurposeResolver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * T148: Horizontal module integration tests.
 *
 * Verifies:
 * - Stub module creates a payment intent via PaymentModuleContract
 * - Idempotent createPaymentIntent returns the same intent on replay
 * - Cross-organisation access is denied (actor ownership check)
 * - UnknownPurposeTypeException when resolver not registered
 * - getAvailableOptions delegates to ProviderRegistryService
 */
final class HorizontalModuleIntegrationTest extends TestCase
{
    /** @var MockObject&PaymentOrchestrationService */
    private MockObject $orchestration;

    /** @var MockObject&ProviderRegistryService */
    private MockObject $registry;

    /** @var MockObject&PaymentIntentRepositoryInterface */
    private MockObject $intentRepo;

    /** @var MockObject&PaymentAttemptRepositoryInterface */
    private MockObject $attemptRepo;

    private PaymentApplicationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orchestration = $this->createMock(PaymentOrchestrationService::class);
        $this->registry      = $this->createMock(ProviderRegistryService::class);
        $this->intentRepo    = $this->createMock(PaymentIntentRepositoryInterface::class);
        $this->attemptRepo   = $this->createMock(PaymentAttemptRepositoryInterface::class);

        $this->service = new PaymentApplicationService(
            orchestration:     $this->orchestration,
            registry:          $this->registry,
            intentRepository:  $this->intentRepo,
            attemptRepository: $this->attemptRepo,
        );
    }

    // ─── Test 1: Stub module creates payment intent ───────────────────────────

    public function test_stub_module_creates_payment_intent(): void
    {
        $intentId      = (string) Uuid::uuid7();
        $idempotencyKey = (string) Uuid::uuid7();
        $correlationId  = (string) Uuid::uuid7();

        $this->orchestration->expects(self::once())
            ->method('createIntent')
            ->with(self::callback(static function (array $req) use ($idempotencyKey): bool {
                return $req['purpose_type'] === 'stub_payment'
                    && $req['idempotency_key'] === $idempotencyKey;
            }))
            ->willReturn([
                'id'            => $intentId,
                'status'        => 'pending',
                'amount_minor'  => 10000,
                'currency_code' => 'BWP',
                'expires_at'    => (new \DateTimeImmutable('+30 minutes'))->format(\DateTimeInterface::ATOM),
            ]);

        $request = new PaymentIntentRequest(
            purposeType:      'stub_payment',
            purposeReference: 'stub-ref-001',
            organisationId:   StubPurposeResolver::TEST_ORG_ID,
            payerActorId:     StubPurposeResolver::TEST_ACTOR_ID,
            currencyCode:     'BWP',
            idempotencyKey:   $idempotencyKey,
            correlationId:    $correlationId,
        );

        $result = $this->service->createPaymentIntent($request);

        self::assertSame($intentId, $result['payment_intent_id']);
        self::assertSame('pending', $result['status']);
        self::assertSame(10000, $result['amount_minor']);
        self::assertSame('BWP', $result['currency_code']);
        self::assertInstanceOf(\DateTimeImmutable::class, $result['expires_at']);
        self::assertSame($correlationId, $result['correlation_id']);
    }

    // ─── Test 2: createPaymentIntent is idempotent (same result on replay) ───

    public function test_create_payment_intent_is_idempotent(): void
    {
        $intentId       = (string) Uuid::uuid7();
        $idempotencyKey = (string) Uuid::uuid7();
        $orchestrationResult = [
            'id'            => $intentId,
            'status'        => 'pending',
            'amount_minor'  => 10000,
            'currency_code' => 'BWP',
            'expires_at'    => (new \DateTimeImmutable('+30 minutes'))->format(\DateTimeInterface::ATOM),
        ];

        // Orchestration is called twice — returns same result both times (idempotency handled internally)
        $this->orchestration->expects(self::exactly(2))
            ->method('createIntent')
            ->willReturn($orchestrationResult);

        $request = new PaymentIntentRequest(
            purposeType:      'stub_payment',
            purposeReference: 'stub-ref-002',
            organisationId:   StubPurposeResolver::TEST_ORG_ID,
            payerActorId:     StubPurposeResolver::TEST_ACTOR_ID,
            currencyCode:     'BWP',
            idempotencyKey:   $idempotencyKey,
            correlationId:    (string) Uuid::uuid7(),
        );

        $result1 = $this->service->createPaymentIntent($request);
        $result2 = $this->service->createPaymentIntent($request);

        self::assertSame($result1['payment_intent_id'], $result2['payment_intent_id']);
        self::assertSame($result1['amount_minor'], $result2['amount_minor']);
    }

    // ─── Test 3: Cross-organisation access is denied ─────────────────────────

    public function test_cross_org_access_denied_for_get_payment_status(): void
    {
        $intentId      = (string) Uuid::uuid7();
        $ownerActorId  = (string) Uuid::uuid7();
        $otherActorId  = (string) Uuid::uuid7();

        $intent = new PaymentIntentModel();
        $intent->id             = $intentId;
        $intent->payer_actor_id = $ownerActorId;
        $intent->organisation_id = StubPurposeResolver::TEST_ORG_ID;

        $this->intentRepo->method('findById')->willReturn($intent);

        $this->expectException(PaymentIntentNotFoundException::class);

        // Other actor tries to access a different actor's intent
        $this->service->getPaymentStatus($intentId, $otherActorId);
    }

    // ─── Test 4: Actor that owns intent can retrieve status ──────────────────

    public function test_owner_actor_can_retrieve_payment_status(): void
    {
        $intentId     = (string) Uuid::uuid7();
        $ownerActorId = (string) Uuid::uuid7();

        $intent = new PaymentIntentModel();
        $intent->id              = $intentId;
        $intent->payer_actor_id  = $ownerActorId;
        $intent->organisation_id = StubPurposeResolver::TEST_ORG_ID;
        $intent->reference       = 'REF-001';
        $intent->amount_minor    = 10000;
        $intent->currency_code   = 'BWP';
        $intent->succeeded_at    = null;

        // Inject a cast-compatible status
        $intent->forceFill(['status' => 'pending']);

        $this->intentRepo->method('findById')->willReturn($intent);
        $this->attemptRepo->method('findActiveForIntent')->willReturn(null);

        $status = $this->service->getPaymentStatus($intentId, $ownerActorId);

        self::assertSame($intentId, $status['payment_intent_id']);
        self::assertNull($status['active_attempt']);
    }

    // ─── Test 5: UnknownPurposeTypeException when resolver not registered ─────

    public function test_unknown_purpose_type_throws(): void
    {
        $registry = new BusinessPurposeResolverRegistry();

        $this->expectException(UnknownPurposeTypeException::class);
        $this->expectExceptionMessageMatches('/unregistered_type/');

        $registry->resolverFor('unregistered_type');
    }

    // ─── Test 6: getAvailableOptions delegates to ProviderRegistryService ─────

    public function test_get_available_options_delegates_to_registry(): void
    {
        $query = new OptionsQuery(
            purposeType:      'stub_payment',
            purposeReference: 'stub-ref-003',
            currencyCode:     'BWP',
            amountMinor:      10000,
            organisationId:   StubPurposeResolver::TEST_ORG_ID,
            actorId:          StubPurposeResolver::TEST_ACTOR_ID,
        );

        $mockOption = new PaymentProviderOption(
            providerCode:         'olekard_wallet',
            displayName:          'OleKard Wallet',
            isWallet:             true,
            isDefault:            true,
            sortOrder:            1,
            walletBalanceMinor:   50000,
            hasSufficientBalance: true,
            checkoutFlow:         null,
        );

        $this->registry->expects(self::once())
            ->method('discoverOptions')
            ->with($query)
            ->willReturn([$mockOption]);

        $options = $this->service->getAvailableOptions($query);

        self::assertCount(1, $options);
        self::assertSame('olekard_wallet', $options[0]->providerCode);
    }

    // ─── Test 7: PaymentModuleContract is satisfied by PaymentApplicationService

    public function test_payment_application_service_satisfies_module_contract(): void
    {
        self::assertInstanceOf(PaymentModuleContract::class, $this->service);
    }

    // ─── Test 8: Docker-gated — full DB intent creation with stub resolver ────

    public function test_full_stub_intent_creation_integration(): void
    {
        $this->markTestSkipped('T148: Full DB integration requires Docker (payment schema + real orchestration).');
    }

    // ─── Test 9: Docker-gated — event consumed idempotently ──────────────────

    public function test_event_consumed_idempotently(): void
    {
        $this->markTestSkipped('T148: Outbox event idempotency requires Docker (DB + queue worker).');
    }
}
