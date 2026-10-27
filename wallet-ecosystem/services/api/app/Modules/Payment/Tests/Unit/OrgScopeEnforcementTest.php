<?php

declare(strict_types=1);

namespace App\Modules\Payment\Tests\Unit;

use App\Modules\Payment\Domain\Contracts\PaymentIntentRepositoryInterface;
use App\Modules\Payment\Http\Middleware\PaymentOrganisationScopeMiddleware;
use App\Modules\Payment\Infrastructure\Models\PaymentIntentModel;
use App\Modules\Platform\Domain\ValueObjects\Actor;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

final class OrgScopeEnforcementTest extends TestCase
{
    private MockObject&PaymentIntentRepositoryInterface $intentRepository;
    private PaymentOrganisationScopeMiddleware $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->intentRepository = $this->createMock(PaymentIntentRepositoryInterface::class);
        $this->middleware        = new PaymentOrganisationScopeMiddleware($this->intentRepository);
    }

    public function test_cross_tenant_returns_403(): void
    {
        $orgA   = 'org-a-uuid';
        $orgB   = 'org-b-uuid';
        $intent = $this->buildIntent(organisationId: $orgA);

        $this->intentRepository
            ->method('findById')
            ->willReturn($intent);

        $request = $this->buildRequest(intentId: 'some-intent-id', actorOrgId: $orgB);
        $response = $this->invokeMiddleware($request);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_same_org_returns_200(): void
    {
        $orgA   = 'org-a-uuid';
        $intent = $this->buildIntent(organisationId: $orgA);

        $this->intentRepository
            ->method('findById')
            ->willReturn($intent);

        $request  = $this->buildRequest(intentId: 'some-intent-id', actorOrgId: $orgA);
        $response = $this->invokeMiddleware($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_passes_through_when_intent_id_is_null(): void
    {
        $this->intentRepository->expects($this->never())->method('findById');

        $request  = $this->buildRequest(intentId: null, actorOrgId: 'org-a-uuid');
        $response = $this->invokeMiddleware($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_system_admin_without_org_id_bypasses_check(): void
    {
        $intent = $this->buildIntent(organisationId: 'org-a-uuid');

        $this->intentRepository
            ->method('findById')
            ->willReturn($intent);

        // System admin: actor has no organisationId (null)
        $request  = $this->buildRequest(intentId: 'some-intent-id', actorOrgId: null);
        $response = $this->invokeMiddleware($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    private function buildIntent(string $organisationId): PaymentIntentModel
    {
        $intent = new PaymentIntentModel();
        $intent->organisation_id = $organisationId;

        return $intent;
    }

    private function buildRequest(?string $intentId, ?string $actorOrgId): Request
    {
        $request = Request::create('/api/v1/payment/intents/' . ($intentId ?? '') . '/refunds', 'POST');

        if ($intentId !== null) {
            $request->setRouteResolver(function () use ($intentId) {
                return new class($intentId) {
                    public function __construct(private readonly string $intentId) {}
                    public function parameter(string $name): ?string
                    {
                        return $name === 'intentId' ? $this->intentId : null;
                    }
                };
            });
        }

        /** @var \App\Modules\Platform\Domain\ValueObjects\Actor|null $actor */
        $actor = $this->createActor($actorOrgId);
        $request->attributes->set('actor', $actor);

        return $request;
    }

    private function invokeMiddleware(Request $request): SymfonyResponse
    {
        return $this->middleware->handle($request, fn() => response('ok', 200));
    }

    private function createActor(?string $orgId): object
    {
        return new class($orgId) {
            public function __construct(public readonly ?string $organisationId) {}
        };
    }
}
