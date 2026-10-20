<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Support;

use Tests\Support\FakeRequirePermissionMiddleware;
use Ramsey\Uuid\Uuid;
use Tests\Feature\Modules\Support\Concerns\CreatesDisputeFixtures;
use Tests\Support\FakeIdentityAuthMiddleware;
use Tests\Support\PlatformTestCase;

final class DisputeRejectTest extends PlatformTestCase
{
    use CreatesDisputeFixtures;

    protected $connectionsToTransact = [null, 'identity', 'platform'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['router']->aliasMiddleware('identity.auth', FakeIdentityAuthMiddleware::class);
        $this->app['router']->aliasMiddleware('identity.require-permission', FakeRequirePermissionMiddleware::class);
    }

    public function test_admin_can_reject_dispute_under_investigation(): void
    {
        $adminId    = (string) Uuid::uuid7();
        $customerId = (string) Uuid::uuid7();
        $dispute    = $this->createDispute((string) Uuid::uuid7(), $customerId, 'under_investigation');

        $response = $this->patchJson("/v1/disputes/{$dispute->id}/reject", [
            'rejection_reason' => 'Transaction logs confirm no error occurred.',
        ], [
            'X-Test-User-Id'     => $adminId,
            'X-Test-Actor-Type'  => 'admin',
            'X-Test-Permissions' => 'dispute.reject',
            'Idempotency-Key'    => (string) Uuid::uuid7(),
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'rejected');
        $this->assertNotNull($response->json('data.rejected_at'));
    }

    public function test_non_admin_cannot_reject_dispute(): void
    {
        $customerId = (string) Uuid::uuid7();
        $dispute    = $this->createDispute((string) Uuid::uuid7(), $customerId, 'under_investigation');

        $response = $this->patchJson("/v1/disputes/{$dispute->id}/reject", [
            'rejection_reason' => 'Attempted rejection by customer.',
        ], [
            'X-Test-User-Id'     => $customerId,
            'X-Test-Actor-Type'  => 'customer',
            'X-Test-Permissions' => 'dispute.reject',
            'Idempotency-Key'    => (string) Uuid::uuid7(),
        ]);

        $response->assertForbidden();
    }

    public function test_rejection_without_reason_returns_422(): void
    {
        $adminId    = (string) Uuid::uuid7();
        $customerId = (string) Uuid::uuid7();
        $dispute    = $this->createDispute((string) Uuid::uuid7(), $customerId, 'under_investigation');

        $response = $this->patchJson("/v1/disputes/{$dispute->id}/reject", [], [
            'X-Test-User-Id'     => $adminId,
            'X-Test-Actor-Type'  => 'admin',
            'X-Test-Permissions' => 'dispute.reject',
            'Idempotency-Key'    => (string) Uuid::uuid7(),
        ]);

        $response->assertStatus(422);
    }

    public function test_rejecting_approved_dispute_returns_422_invalid_transition(): void
    {
        $adminId    = (string) Uuid::uuid7();
        $customerId = (string) Uuid::uuid7();
        // Approved disputes cannot be directly rejected (must be refunded)
        $dispute    = $this->createDispute((string) Uuid::uuid7(), $customerId, 'approved');

        $response = $this->patchJson("/v1/disputes/{$dispute->id}/reject", [
            'rejection_reason' => 'Attempted reject on approved dispute.',
        ], [
            'X-Test-User-Id'     => $adminId,
            'X-Test-Actor-Type'  => 'admin',
            'X-Test-Permissions' => 'dispute.reject',
            'Idempotency-Key'    => (string) Uuid::uuid7(),
        ]);

        $response->assertStatus(422);
    }

    public function test_rejection_is_idempotent(): void
    {
        $adminId        = (string) Uuid::uuid7();
        $customerId     = (string) Uuid::uuid7();
        $dispute        = $this->createDispute((string) Uuid::uuid7(), $customerId, 'under_investigation');
        $idempotencyKey = (string) Uuid::uuid7();

        $payload = ['rejection_reason' => 'Transaction logs confirm no error occurred.'];
        $headers = [
            'X-Test-User-Id'     => $adminId,
            'X-Test-Actor-Type'  => 'admin',
            'X-Test-Permissions' => 'dispute.reject',
            'Idempotency-Key'    => $idempotencyKey,
        ];

        $r1 = $this->patchJson("/v1/disputes/{$dispute->id}/reject", $payload, $headers);
        $r2 = $this->patchJson("/v1/disputes/{$dispute->id}/reject", $payload, $headers);

        $r1->assertOk();
        $r2->assertOk();
        $this->assertSame('rejected', $r2->json('data.status'));
    }

    public function test_rejection_publishes_dispute_rejected_event_via_outbox(): void
    {
        $adminId    = (string) Uuid::uuid7();
        $customerId = (string) Uuid::uuid7();
        $dispute    = $this->createDispute((string) Uuid::uuid7(), $customerId, 'under_investigation');

        $this->patchJson("/v1/disputes/{$dispute->id}/reject", [
            'rejection_reason' => 'No evidence of system error.',
        ], [
            'X-Test-User-Id'     => $adminId,
            'X-Test-Actor-Type'  => 'admin',
            'X-Test-Permissions' => 'dispute.reject',
            'Idempotency-Key'    => (string) Uuid::uuid7(),
        ])->assertOk();

        $this->assertOutboxEventPublished('Support.Dispute.Rejected', ['dispute_id' => $dispute->id]);
    }
}
