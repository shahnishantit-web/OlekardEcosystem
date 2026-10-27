<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Identity;

use App\Modules\CommunityFuneral\Domain\Contracts\OrganisationMembershipVerifier;
use App\Modules\Identity\Application\Services\ReviewedOrganisationMembershipService;
use App\Modules\Identity\Domain\Contracts\IdentityModuleContract;
use Illuminate\Support\Facades\DB;
use Mockery;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\IdentityTestCase;

final class ReviewedOrganisationMembershipTest extends IdentityTestCase
{
    protected $connectionsToTransact = ['identity'];

    private string $maker;
    private string $reviewer;
    private string $target;
    private ReviewedOrganisationMembershipService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->maker = $this->user('ADMIN');
        $this->reviewer = $this->user('ADMIN');
        $this->target = $this->user('CUSTOMER');
        $identity = Mockery::mock(IdentityModuleContract::class);
        $identity->shouldReceive('hasPermission')->andReturnUsing(
            fn (string $actorId, string $permission): bool => in_array($actorId, [$this->maker, $this->reviewer], true)
                && in_array($permission, ['organisation.registry.request', 'organisation.registry.review', 'organisation.registry.revoke'], true),
        );
        $this->service = new ReviewedOrganisationMembershipService($identity);
    }

    public function test_reviewed_membership_is_required_and_revocation_is_immediate(): void
    {
        $org = $this->service->requestOrganisation('test-registry-'.Uuid::uuid7(), 'Test community', 'restricted-evidence-1', $this->maker);
        $this->assertDenied($org['id']);
        $this->assertHttp(403, fn () => $this->service->reviewOrganisation($org['id'], 'active', $this->maker));
        $this->service->reviewOrganisation($org['id'], 'active', $this->reviewer);
        $membership = $this->service->requestMembership($org['id'], $this->target, 'restricted-evidence-2', $this->maker);
        $this->assertDenied($org['id']);
        $this->assertHttp(403, fn () => $this->service->reviewMembership($membership['id'], 'active', $this->maker));
        $this->service->reviewMembership($membership['id'], 'active', $this->reviewer);
        $this->service->assertActiveMembership($this->target, $org['id']);
        app(OrganisationMembershipVerifier::class)->assertActiveMembership($this->target, $org['id']);
        $this->assertHttp(409, fn () => $this->service->reviewMembership($membership['id'], 'active', $this->reviewer));

        $this->service->revokeMembership($membership['id'], $this->reviewer);
        $this->assertDenied($org['id']);
        $this->assertSame(['pending_review', 'active', 'revoked'], DB::connection('identity')
            ->table('identity.organisation_authority_events')->where('subject_id', $membership['id'])
            ->orderBy('created_at')->orderBy('id')->pluck('new_status')->all());
    }

    public function test_cross_organisation_inactive_user_and_suspended_org_fail_closed(): void
    {
        $org = $this->service->requestOrganisation('test-registry-'.Uuid::uuid7(), 'Test community', 'restricted-evidence', $this->maker);
        $this->service->reviewOrganisation($org['id'], 'active', $this->reviewer);
        $membership = $this->service->requestMembership($org['id'], $this->target, 'restricted-evidence', $this->maker);
        $this->service->reviewMembership($membership['id'], 'active', $this->reviewer);
        $this->assertHttp(403, fn () => $this->service->assertActiveMembership($this->target, (string) Uuid::uuid7()));
        DB::connection('identity')->table('identity.users')->where('id', $this->target)->update(['status' => 'locked']);
        $this->assertDenied($org['id']);
        DB::connection('identity')->table('identity.users')->where('id', $this->target)->update(['status' => 'active']);
        $this->service->suspendOrganisation($org['id'], $this->reviewer);
        $this->assertDenied($org['id']);
    }

    public function test_regrant_requires_new_review_and_customer_cannot_request(): void
    {
        $org = $this->service->requestOrganisation('test-registry-'.Uuid::uuid7(), 'Test community', 'restricted-evidence', $this->maker);
        $this->service->reviewOrganisation($org['id'], 'active', $this->reviewer);
        $this->assertHttp(403, fn () => $this->service->requestMembership($org['id'], $this->target, 'evidence', $this->target));
        $membership = $this->service->requestMembership($org['id'], $this->target, 'evidence', $this->maker);
        $this->service->reviewMembership($membership['id'], 'active', $this->reviewer);
        $this->service->revokeMembership($membership['id'], $this->reviewer);
        $second = $this->service->requestMembership($org['id'], $this->target, 'new-restricted-evidence', $this->maker);
        $this->assertSame($membership['id'], $second['id']);
        $this->assertDenied($org['id']);
        $this->service->reviewMembership($second['id'], 'active', $this->reviewer);
        $this->service->assertActiveMembership($this->target, $org['id']);
        $this->addToAssertionCount(1);
    }

    private function assertDenied(string $orgId): void
    {
        $this->assertHttp(403, fn () => $this->service->assertActiveMembership($this->target, $orgId));
    }

    private function assertHttp(int $status, callable $action): void
    {
        try {
            $action();
            $this->fail('Expected HTTP '.$status);
        } catch (HttpException $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        }
    }

    private function user(string $type): string
    {
        $id = (string) Uuid::uuid7();
        DB::connection('identity')->table('identity.users')->insert([
            'id' => $id, 'status' => 'active', 'actor_type' => $type,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $id;
    }
}
