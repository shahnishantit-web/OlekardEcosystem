<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Support;

use Tests\Support\FakeRequirePermissionMiddleware;
use Ramsey\Uuid\Uuid;
use Tests\Feature\Modules\Support\Concerns\CreatesDisputeFixtures;
use Tests\Support\FakeIdentityAuthMiddleware;
use Tests\Support\PlatformTestCase;

final class DisputeInvestigateTest extends PlatformTestCase
{
    use CreatesDisputeFixtures;

    protected $connectionsToTransact = [null, 'identity', 'platform'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['router']->aliasMiddleware('identity.auth', FakeIdentityAuthMiddleware::class);
        $this->app['router']->aliasMiddleware('identity.require-permission', FakeRequirePermissionMiddleware::class);
    }

    public function test_admin_can_start_investigation_on_raised_dispute(): void
    {
        $adminId       = (string) Uuid::uuid7();
        $customerId    = (string) Uuid::uuid7();
        $dispute       = $this->createDispute((string) Uuid::uuid7(), $customerId, 'raised');

        $response = $this->patchJson("/v1/disputes/{$dispute->id}/investigate", [
            'investigator_id' => $adminId,
        ], [
            'X-Test-User-Id'     => $adminId,
            'X-Test-Actor-Type'  => 'admin',
            'X-Test-Permissions' => 'support.investigate',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'under_investigation');
        $response->assertJsonPath('data.investigator_id', $adminId);
    }

    public function test_admin_can_investigate_raised_dispute_without_prior_evidence(): void
    {
        $adminId    = (string) Uuid::uuid7();
        $customerId = (string) Uuid::uuid7();
        // Dispute has no evidence (status=raised), should still transition
        $dispute    = $this->createDispute((string) Uuid::uuid7(), $customerId, 'raised');

        $response = $this->patchJson("/v1/disputes/{$dispute->id}/investigate", [
            'investigator_id' => $adminId,
        ], [
            'X-Test-User-Id'     => $adminId,
            'X-Test-Actor-Type'  => 'admin',
            'X-Test-Permissions' => 'support.investigate',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'under_investigation');
    }

    public function test_second_investigate_call_returns_422_invalid_transition(): void
    {
        $adminId    = (string) Uuid::uuid7();
        $customerId = (string) Uuid::uuid7();
        $dispute    = $this->createDispute((string) Uuid::uuid7(), $customerId, 'under_investigation');

        $response = $this->patchJson("/v1/disputes/{$dispute->id}/investigate", [
            'investigator_id' => $adminId,
        ], [
            'X-Test-User-Id'     => $adminId,
            'X-Test-Actor-Type'  => 'admin',
            'X-Test-Permissions' => 'support.investigate',
        ]);

        $response->assertStatus(422);
    }

    public function test_reporter_cannot_start_investigation(): void
    {
        $customerId = (string) Uuid::uuid7();
        $dispute    = $this->createDispute((string) Uuid::uuid7(), $customerId, 'raised');

        $response = $this->patchJson("/v1/disputes/{$dispute->id}/investigate", [
            'investigator_id' => $customerId,
        ], [
            'X-Test-User-Id'     => $customerId,
            'X-Test-Actor-Type'  => 'customer',
            'X-Test-Permissions' => 'support.investigate',
        ]);

        $response->assertForbidden();
    }
}
