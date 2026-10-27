<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\CommunityFuneral;

use App\Modules\CommunityFuneral\Domain\Contracts\OrganisationMembershipVerifier;
use App\Modules\Payment\Domain\Enums\PayoutBeneficiaryStatus;
use App\Modules\Payment\Infrastructure\Models\PayoutBeneficiaryModel;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Feature\Modules\Integration\IntegrationTestCase;

final class FuneralRepresentativeHttpTest extends IntegrationTestCase
{
    protected $connectionsToTransact = [null, 'identity', 'platform', 'payment', 'community_services', 'notification'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['community_funeral.http_enabled' => true, 'queue.default' => 'sync']);
        $this->app->instance(OrganisationMembershipVerifier::class, new class implements OrganisationMembershipVerifier {
            public function assertActiveMembership(string $actorId, string $organisationId): void {}
        });
    }

    public function test_representative_lifecycle_verification_and_revocation(): void
    {
        $manager = $this->makeAdminWithPermission('community.funeral.manage');
        $reviewer = $this->makeAdminWithPermission('community.funeral.review');
        $customer = $this->makeActorWithPermission('CUSTOMER', 'community.funeral.household.read');
        $orgId = (string) Uuid::uuid7();

        $url = '/api/v1/community/funeral';

        // 1. Create Community and Household
        $this->withActorToken($manager, ['Idempotency-Key' => (string) Uuid::uuid7()]);
        $comm = $this->postJson("$url/communities", [
            'organisation_id' => $orgId,
            'display_name' => 'Rep Test Community',
        ])->assertCreated();
        $communityId = $comm->json('data.id');

        $this->withActorToken($manager, ['Idempotency-Key' => (string) Uuid::uuid7()]);
        $this->postJson("$url/communities/$communityId/staff", [
            'actor_id' => $reviewer->id,
            'role' => 'reviewer',
        ])->assertOk();

        $this->withActorToken($manager, ['Idempotency-Key' => (string) Uuid::uuid7()]);
        $household = $this->postJson("$url/communities/$communityId/households", [
            'private_reference' => 'PRIV-HH-001',
        ])->assertCreated();
        $householdId = $household->json('data.id');

        // Review household as eligible
        $this->withActorToken($reviewer, ['Idempotency-Key' => (string) Uuid::uuid7()]);
        $this->postJson("$url/households/$householdId/review", [
            'decision' => 'eligible',
        ])->assertOk();

        // 2. Add Representative (unverified)
        $this->withActorToken($manager, ['Idempotency-Key' => (string) Uuid::uuid7()]);
        $repRes = $this->postJson("$url/households/$householdId/representatives", [
            'actor_id' => $customer->id,
            'evidence_reference' => 'evidence-doc-ref-123',
        ])->assertCreated();
        $repId = $repRes->json('data.id');
        $this->assertSame('unverified', $repRes->json('data.status'));

        // 3. Verify Representative
        $this->withActorToken($reviewer, ['Idempotency-Key' => (string) Uuid::uuid7()]);
        $this->postJson("$url/households/$householdId/representatives/$repId/verify")
            ->assertOk()
            ->assertJsonPath('data.status', 'verified');

        $this->assertSame('verified', DB::connection('community_services')
            ->table('community_services.funeral_representatives')->where('id', $repId)->value('status'));

        // 4. Revoke Representative
        $this->withActorToken($reviewer, ['Idempotency-Key' => (string) Uuid::uuid7()]);
        $this->postJson("$url/households/$householdId/representatives/$repId/revoke")
            ->assertOk()
            ->assertJsonPath('data.status', 'revoked');

        $this->assertSame('revoked', DB::connection('community_services')
            ->table('community_services.funeral_representatives')->where('id', $repId)->value('status'));
    }

    public function test_customer_own_household_obligations_and_cross_actor_denial(): void
    {
        $manager = $this->makeAdminWithPermission('community.funeral.manage');
        $reviewer = $this->makeAdminWithPermission('community.funeral.review');
        $repCustomer = $this->makeActorWithPermission('CUSTOMER', 'community.funeral.household.read');
        $outsiderCustomer = $this->makeActorWithPermission('CUSTOMER', 'community.funeral.household.read');
        $orgId = (string) Uuid::uuid7();

        $beneficiary = PayoutBeneficiaryModel::create([
            'id' => (string) Uuid::uuid7(), 'organisation_id' => $orgId,
            'account_type' => 'bank_account', 'account_number' => 'ACC-TEST-REP',
            'account_holder_name' => 'Beneficiary Rep', 'currency_code' => 'BWP',
            'status' => PayoutBeneficiaryStatus::Verified, 'verified_at' => now(), 'verification_reference' => 'REF-REP',
            'created_by' => $manager->id,
        ]);

        $url = '/api/v1/community/funeral';

        // Create community, staff, household, rep
        $this->withActorToken($manager, ['Idempotency-Key' => (string) Uuid::uuid7()]);
        $comm = $this->postJson("$url/communities", ['organisation_id' => $orgId, 'display_name' => 'Obligations Test Comm'])->assertCreated();
        $communityId = $comm->json('data.id');

        $this->withActorToken($manager, ['Idempotency-Key' => (string) Uuid::uuid7()]);
        $this->postJson("$url/communities/$communityId/staff", ['actor_id' => $reviewer->id, 'role' => 'reviewer'])->assertOk();

        $this->withActorToken($manager, ['Idempotency-Key' => (string) Uuid::uuid7()]);
        $hh = $this->postJson("$url/communities/$communityId/households", ['private_reference' => 'PRIV-HH-OBL'])->assertCreated();
        $householdId = $hh->json('data.id');

        $this->withActorToken($reviewer, ['Idempotency-Key' => (string) Uuid::uuid7()]);
        $this->postJson("$url/households/$householdId/review", ['decision' => 'eligible'])->assertOk();

        $this->withActorToken($manager, ['Idempotency-Key' => (string) Uuid::uuid7()]);
        $rep = $this->postJson("$url/households/$householdId/representatives", [
            'actor_id' => $repCustomer->id, 'evidence_reference' => 'restricted-rep-evidence',
        ])->assertCreated();
        $repId = $rep->json('data.id');

        $this->withActorToken($reviewer, ['Idempotency-Key' => (string) Uuid::uuid7()]);
        $this->postJson("$url/households/$householdId/representatives/$repId/verify")->assertOk();

        // Create and activate collection
        $this->withActorToken($manager, ['Idempotency-Key' => (string) Uuid::uuid7()]);
        $coll = $this->postJson("$url/communities/$communityId/collections", [
            'beneficiary_id' => $beneficiary->id, 'evidence_reference' => 'restricted-coll-evidence',
        ])->assertCreated();
        $collectionId = $coll->json('data.id');

        $this->withActorToken($reviewer, ['Idempotency-Key' => (string) Uuid::uuid7()]);
        $this->postJson("$url/collections/$collectionId/review")->assertOk();

        $this->withActorToken($reviewer, ['Idempotency-Key' => (string) Uuid::uuid7()]);
        $this->postJson("$url/collections/$collectionId/activate")->assertOk();

        // Verified representative fetches their obligations
        $this->withActorToken($repCustomer);
        $res = $this->getJson("$url/my/obligations")->assertOk();
        $obligations = $res->json('data');
        $this->assertCount(1, $obligations);
        $this->assertSame(2000, (int) $obligations[0]['amount_minor']);
        $this->assertSame('BWP', $obligations[0]['currency_code']);
        $this->assertSame('unpaid', $obligations[0]['status']);
        $this->assertSame($collectionId, $obligations[0]['collection_id']);

        // Outsider customer fetches their obligations -> sees empty array (no access to other households)
        $this->withActorToken($outsiderCustomer);
        $outsiderRes = $this->getJson("$url/my/obligations")->assertOk();
        $this->assertSame([], $outsiderRes->json('data'));
    }
}
