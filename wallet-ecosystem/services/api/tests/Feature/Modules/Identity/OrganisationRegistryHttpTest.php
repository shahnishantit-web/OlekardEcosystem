<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Identity;

use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Support\LedgerTestCase;

final class OrganisationRegistryHttpTest extends LedgerTestCase
{
    protected $connectionsToTransact = ['identity'];

    public function test_admin_request_is_pending_and_cannot_self_approve(): void
    {
        $maker = $this->makeAdminWithPermission('organisation.registry.request', 'organisation.registry.review');
        $this->withActorToken($maker);
        $this->withHeader('Idempotency-Key', (string) Uuid::uuid7());
        $response = $this->postJson('/v1/identity/organisations', [
            'registry_reference' => 'http-test-'.Uuid::uuid7(),
            'display_name' => 'Restricted test organisation',
            'evidence_reference' => 'restricted-test-evidence',
        ]);
        $response->assertCreated()->assertJsonPath('data.status', 'pending_review');
        $id = $response->json('data.id');
        $this->assertSame('pending_review', DB::connection('identity')->table('identity.organisations')->where('id', $id)->value('status'));
        $this->withHeader('Idempotency-Key', (string) Uuid::uuid7())
            ->postJson('/v1/identity/organisations/'.$id.'/review', ['decision' => 'active'])
            ->assertForbidden();
    }
}
