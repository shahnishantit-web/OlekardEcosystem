<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\CommunityFuneral;

use App\Modules\CommunityFuneral\Http\Middleware\FuneralReadinessGate;
use App\Modules\CommunityFuneral\Application\FuneralObligationResolver;
use App\Modules\CommunityFuneral\Infrastructure\DenyUnresolvedOrganisationMembership;
use App\Modules\Payment\Domain\Exceptions\PurposeNotPayableException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Tests\TestCase;
use Tests\Support\FakeIdentityAuthMiddleware;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class FuneralReadinessGateTest extends TestCase
{
    public function test_http_is_closed_by_default(): void
    {
        $response = (new FuneralReadinessGate())->handle(Request::create('/api/v1/community/funeral/my/obligations'),
            static fn (): Response => new Response('open'));

        $this->assertSame(503, $response->getStatusCode());
        $this->assertStringContainsString('FUNERAL_ASSISTANCE_PREREQUISITES_UNRESOLVED', (string) $response->getContent());
    }

    public function test_production_stays_closed_if_config_is_changed(): void
    {
        config(['community_funeral.http_enabled' => true]);
        $this->app['env'] = 'production';
        $response = (new FuneralReadinessGate())->handle(Request::create('/api/v1/community/funeral/communities'),
            static fn (): Response => new Response('open'));

        $this->assertSame(503, $response->getStatusCode());
    }

    public function test_shared_checkout_cannot_resolve_funeral_purpose_while_closed(): void
    {
        $this->expectException(PurposeNotPayableException::class);
        (new FuneralObligationResolver())->resolve('unavailable', 'unavailable', 'test-correlation');
    }

    public function test_unresolved_organisation_membership_denies_access(): void
    {
        $this->expectException(HttpException::class);
        (new DenyUnresolvedOrganisationMembership())->assertActiveMembership('actor', 'caller-supplied-org');
    }

    public function test_registered_customer_route_returns_prerequisite_gate(): void
    {
        $this->app['router']->aliasMiddleware('identity.auth', FakeIdentityAuthMiddleware::class);
        $this->withHeaders(['X-Test-User-Id' => 'test-only', 'X-Test-Actor-Type' => 'CUSTOMER'])
            ->getJson('/api/v1/community/funeral/my/obligations')
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'FUNERAL_ASSISTANCE_PREREQUISITES_UNRESOLVED');
    }
}
