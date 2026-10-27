<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\CommunityFuneral;

use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Feature\Modules\Integration\IntegrationTestCase;

/** Exercises the real Identity membership binding with the P24 gate open only in tests. */
final class FuneralTenantHttpTest extends IntegrationTestCase
{
    protected $connectionsToTransact = [null, 'identity', 'platform', 'community_services'];

    public function test_membership_and_replay_are_scoped_to_authenticated_actor(): void
    {
        config(['community_funeral.http_enabled' => true]);
        $owner = $this->makeAdminWithPermission('community.funeral.manage');
        $other = (string) Uuid::uuid7();
        $role = DB::connection('identity')->table('identity.users')->where('id', $owner->id)->value('default_role_id');
        DB::connection('identity')->table('identity.users')->insert([
            'id' => $other, 'status' => 'active', 'actor_type' => 'ADMIN', 'default_role_id' => $role,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $orgId = (string) Uuid::uuid7();
        DB::connection('identity')->table('identity.organisations')->insert([
            'id' => $orgId, 'registry_reference' => 'http-test-'.Uuid::uuid7(),
            'display_name' => 'Test organisation', 'status' => 'active', 'evidence_reference' => 'test-only',
            'requested_by' => $owner->id, 'reviewed_by' => $other, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $memberId = (string) Uuid::uuid7();
        DB::connection('identity')->table('identity.organisation_memberships')->insert([
            'id' => $memberId, 'organisation_id' => $orgId, 'user_id' => $owner->id,
            'status' => 'active', 'evidence_reference' => 'test-only', 'requested_by' => $other,
            'reviewed_by' => $other, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $key = (string) Uuid::uuid7();
        $payload = ['organisation_id' => $orgId, 'display_name' => 'Private test community'];
        $url = '/api/v1/community/funeral/communities';
        $this->withActorToken($owner, ['Idempotency-Key' => $key, 'X-Actor-ID' => $owner->id]);
        $created = $this->postJson($url, $payload)->assertCreated();
        $communityId = $created->json('data.id');

        // Even a copied key and spoofed actor header cannot replay another actor's response.
        $this->withHeaders(['X-Test-User-Id' => $other, 'X-Test-Actor-Type' => 'ADMIN',
            'Idempotency-Key' => $key, 'X-Actor-ID' => $owner->id]);
        $this->postJson($url, $payload)->assertForbidden();
        $this->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson($url.'/'.$communityId.'/households', ['private_reference' => 'private'])->assertForbidden();

        $this->withActorToken($owner, ['Idempotency-Key' => (string) Uuid::uuid7()]);
        $household = $this->postJson($url.'/'.$communityId.'/households', ['private_reference' => 'private']);
        $household->assertCreated()->assertJsonPath('data.status', 'pending_review');
        DB::connection('identity')->table('identity.organisation_memberships')->where('id', $memberId)->update(['status' => 'revoked']);
        $this->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson($url.'/'.$communityId.'/households', ['private_reference' => 'another'])->assertForbidden();
        $this->assertSame(1, DB::connection('community_services')->table('community_services.funeral_households')
            ->where('community_id', $communityId)->count());
    }
}
