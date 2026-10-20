<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Support;

use Tests\Support\FakeRequirePermissionMiddleware;
use Ramsey\Uuid\Uuid;
use Tests\Feature\Modules\Support\Concerns\CreatesDisputeFixtures;
use Tests\Support\FakeIdentityAuthMiddleware;
use Tests\Support\PlatformTestCase;

final class DisputeApproveTest extends PlatformTestCase
{
    use CreatesDisputeFixtures;

    protected $connectionsToTransact = [null, 'identity', 'platform'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['router']->aliasMiddleware('identity.auth', FakeIdentityAuthMiddleware::class);
        $this->app['router']->aliasMiddleware('identity.require-permission', FakeRequirePermissionMiddleware::class);
    }

    public function test_admin_can_initiate_approval_moving_dispute_to_pending_confirmation(): void
    {
        $adminId    = (string) Uuid::uuid7();
        $customerId = (string) Uuid::uuid7();
        $dispute    = $this->createDispute((string) Uuid::uuid7(), $customerId, 'under_investigation');

        $response = $this->patchJson("/v1/disputes/{$dispute->id}/approve", [
            'resolution_note' => 'Investigation complete. Customer claim validated.',
        ], [
            'X-Test-User-Id'     => $adminId,
            'X-Test-Actor-Type'  => 'admin',
            'X-Test-Permissions' => 'dispute.approve',
            'Idempotency-Key'    => (string) Uuid::uuid7(),
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'pending_confirmation');
    }

    public function test_second_admin_can_confirm_approval_moving_to_approved(): void
    {
        $admin1Id   = (string) Uuid::uuid7();
        $admin2Id   = (string) Uuid::uuid7();
        $customerId = (string) Uuid::uuid7();
        $dispute    = $this->createDispute((string) Uuid::uuid7(), $customerId, 'under_investigation');

        // First admin approves
        $this->patchJson("/v1/disputes/{$dispute->id}/approve", [
            'resolution_note' => 'Customer claim validated.',
        ], [
            'X-Test-User-Id'     => $admin1Id,
            'X-Test-Actor-Type'  => 'admin',
            'X-Test-Permissions' => 'dispute.approve',
            'Idempotency-Key'    => (string) Uuid::uuid7(),
        ])->assertOk();

        // Second admin confirms
        $response = $this->patchJson("/v1/disputes/{$dispute->id}/confirm-approval", [], [
            'X-Test-User-Id'     => $admin2Id,
            'X-Test-Actor-Type'  => 'admin',
            'X-Test-Permissions' => 'dispute.approve',
            'Idempotency-Key'    => (string) Uuid::uuid7(),
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'approved');
    }

    public function test_same_actor_cannot_confirm_their_own_approval(): void
    {
        $adminId    = (string) Uuid::uuid7();
        $customerId = (string) Uuid::uuid7();
        $dispute    = $this->createDispute((string) Uuid::uuid7(), $customerId, 'under_investigation');

        $this->patchJson("/v1/disputes/{$dispute->id}/approve", [
            'resolution_note' => 'Customer claim validated.',
        ], [
            'X-Test-User-Id'     => $adminId,
            'X-Test-Actor-Type'  => 'admin',
            'X-Test-Permissions' => 'dispute.approve',
            'Idempotency-Key'    => (string) Uuid::uuid7(),
        ])->assertOk();

        $response = $this->patchJson("/v1/disputes/{$dispute->id}/confirm-approval", [], [
            'X-Test-User-Id'     => $adminId,
            'X-Test-Actor-Type'  => 'admin',
            'X-Test-Permissions' => 'dispute.approve',
            'Idempotency-Key'    => (string) Uuid::uuid7(),
        ]);

        $response->assertForbidden();
    }

    public function test_approval_of_wrong_status_returns_422(): void
    {
        $adminId    = (string) Uuid::uuid7();
        $customerId = (string) Uuid::uuid7();
        // Dispute is already approved — cannot approve again
        $dispute    = $this->createDispute((string) Uuid::uuid7(), $customerId, 'approved');

        $response = $this->patchJson("/v1/disputes/{$dispute->id}/approve", [
            'resolution_note' => 'Attempting re-approval.',
        ], [
            'X-Test-User-Id'     => $adminId,
            'X-Test-Actor-Type'  => 'admin',
            'X-Test-Permissions' => 'dispute.approve',
            'Idempotency-Key'    => (string) Uuid::uuid7(),
        ]);

        $response->assertStatus(422);
    }

    public function test_approval_without_resolution_note_returns_422(): void
    {
        $adminId    = (string) Uuid::uuid7();
        $customerId = (string) Uuid::uuid7();
        $dispute    = $this->createDispute((string) Uuid::uuid7(), $customerId, 'under_investigation');

        $response = $this->patchJson("/v1/disputes/{$dispute->id}/approve", [], [
            'X-Test-User-Id'     => $adminId,
            'X-Test-Actor-Type'  => 'admin',
            'X-Test-Permissions' => 'dispute.approve',
            'Idempotency-Key'    => (string) Uuid::uuid7(),
        ]);

        $response->assertStatus(422);
    }

    public function test_approval_is_idempotent(): void
    {
        $adminId        = (string) Uuid::uuid7();
        $customerId     = (string) Uuid::uuid7();
        $dispute        = $this->createDispute((string) Uuid::uuid7(), $customerId, 'under_investigation');
        $idempotencyKey = (string) Uuid::uuid7();

        $payload = ['resolution_note' => 'Customer claim validated.'];
        $headers = [
            'X-Test-User-Id'     => $adminId,
            'X-Test-Actor-Type'  => 'admin',
            'X-Test-Permissions' => 'dispute.approve',
            'Idempotency-Key'    => $idempotencyKey,
        ];

        $r1 = $this->patchJson("/v1/disputes/{$dispute->id}/approve", $payload, $headers);
        $r2 = $this->patchJson("/v1/disputes/{$dispute->id}/approve", $payload, $headers);

        $r1->assertOk();
        $r2->assertOk();
        $this->assertSame($r1->json('data.id'), $r2->json('data.id'));
        $this->assertSame('pending_confirmation', $r2->json('data.status'));
    }
}
