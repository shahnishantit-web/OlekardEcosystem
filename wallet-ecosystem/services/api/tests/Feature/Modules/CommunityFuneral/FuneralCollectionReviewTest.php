<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\CommunityFuneral;

use App\Modules\CommunityFuneral\Domain\Contracts\OrganisationMembershipVerifier;
use App\Modules\Payment\Domain\Enums\PayoutBeneficiaryStatus;
use App\Modules\Payment\Infrastructure\Models\PayoutBeneficiaryModel;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Feature\Modules\Integration\IntegrationTestCase;

final class FuneralCollectionReviewTest extends IntegrationTestCase
{
    protected $connectionsToTransact = [null, 'identity', 'platform', 'payment', 'community_services'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['community_funeral.http_enabled' => true, 'queue.default' => 'sync']);
        $this->app->instance(OrganisationMembershipVerifier::class, new class implements OrganisationMembershipVerifier {
            public function assertActiveMembership(string $actorId, string $organisationId): void {}
        });
    }

    public function test_collection_review_and_beneficiary_edit_invalidation(): void
    {
        $manager = $this->makeAdminWithPermission('community.funeral.manage');
        $reviewer = $this->makeAdminWithPermission('community.funeral.review');
        $orgId = (string) Uuid::uuid7();

        $beneficiary1 = PayoutBeneficiaryModel::create([
            'id' => (string) Uuid::uuid7(), 'organisation_id' => $orgId,
            'account_type' => 'bank_account', 'account_number' => 'ACC-TEST-1',
            'account_holder_name' => 'Beneficiary One', 'currency_code' => 'BWP',
            'status' => PayoutBeneficiaryStatus::Verified, 'verified_at' => now(), 'verification_reference' => 'REF-1',
            'created_by' => $manager->id,
        ]);

        $beneficiary2 = PayoutBeneficiaryModel::create([
            'id' => (string) Uuid::uuid7(), 'organisation_id' => $orgId,
            'account_type' => 'bank_account', 'account_number' => 'ACC-TEST-2',
            'account_holder_name' => 'Beneficiary Two', 'currency_code' => 'BWP',
            'status' => PayoutBeneficiaryStatus::Verified, 'verified_at' => now(), 'verification_reference' => 'REF-2',
            'created_by' => $manager->id,
        ]);

        $url = '/api/v1/community/funeral';

        // 1. Create Community
        $this->withActorToken($manager, ['Idempotency-Key' => (string) Uuid::uuid7()]);
        $commRes = $this->postJson("$url/communities", [
            'organisation_id' => $orgId,
            'display_name' => 'Funeral Review Test Community',
        ])->assertCreated();
        $communityId = $commRes->json('data.id');

        // Add staff reviewer
        $this->withActorToken($manager, ['Idempotency-Key' => (string) Uuid::uuid7()]);
        $this->postJson("$url/communities/$communityId/staff", [
            'actor_id' => $reviewer->id,
            'role' => 'reviewer',
        ])->assertOk();

        // 2. Create Collection
        $this->withActorToken($manager, ['Idempotency-Key' => (string) Uuid::uuid7()]);
        $collRes = $this->postJson("$url/communities/$communityId/collections", [
            'beneficiary_id' => $beneficiary1->id,
            'evidence_reference' => 'restricted-evidence-test',
        ])->assertCreated();
        $collectionId = $collRes->json('data.id');
        $this->assertSame('under_review', $collRes->json('data.status'));

        // Manager without review permission cannot approve collection
        $this->withActorToken($manager, ['Idempotency-Key' => (string) Uuid::uuid7()]);
        $this->postJson("$url/collections/$collectionId/review")->assertForbidden();

        // Reviewer approves collection
        $this->withActorToken($reviewer, ['Idempotency-Key' => (string) Uuid::uuid7()]);
        $this->postJson("$url/collections/$collectionId/review")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        // Verify in DB
        $this->assertSame('approved', DB::connection('community_services')
            ->table('community_services.funeral_collections')->where('id', $collectionId)->value('status'));

        // 3. Beneficiary edit clears reviewer and returns to under_review
        $this->withActorToken($manager, ['Idempotency-Key' => (string) Uuid::uuid7()]);
        $this->patchJson("$url/collections/$collectionId/beneficiary", [
            'beneficiary_id' => $beneficiary2->id,
        ])->assertOk()->assertJsonPath('data.status', 'under_review');

        $collection = DB::connection('community_services')
            ->table('community_services.funeral_collections')->where('id', $collectionId)->first();
        $this->assertSame('under_review', $collection->status);
        $this->assertNull($collection->reviewed_by);
        $this->assertSame($beneficiary2->id, $collection->beneficiary_id);

        // 4. Activating unapproved collection is rejected
        $this->withActorToken($reviewer, ['Idempotency-Key' => (string) Uuid::uuid7()]);
        $this->postJson("$url/collections/$collectionId/activate")->assertStatus(422);
    }

    public function test_payout_request_endpoint_returns_503_and_preserves_blocked_liability(): void
    {
        $finance = $this->makeAdminWithPermission('community.funeral.payout.request');
        $collectionId = (string) Uuid::uuid7();

        $this->withActorToken($finance, ['Idempotency-Key' => (string) Uuid::uuid7()]);
        $response = $this->postJson("/api/v1/community/funeral/collections/$collectionId/payout-request");

        $response->assertStatus(503)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'PAYOUT_PREREQUISITES_UNRESOLVED')
            ->assertJsonPath('error.message', 'Family disbursement is unavailable until the shared P18 payout contract and finance approvals are released.');
    }
}
