<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Support;

use App\Modules\Fees\Domain\Contracts\FeesModuleContract;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use Ramsey\Uuid\Uuid;
use Tests\Feature\Modules\Support\Concerns\CreatesDisputeFixtures;
use Tests\Support\FakeIdentityAuthMiddleware;
use Tests\Support\FakeRequirePermissionMiddleware;
use Tests\Support\PlatformTestCase;

final class DisputeRefundTest extends PlatformTestCase
{
    use CreatesDisputeFixtures, \Tests\Feature\Modules\Support\Concerns\CreatesSecureRefundContext;

    protected $connectionsToTransact = [null, 'identity', 'platform'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['router']->aliasMiddleware('identity.auth', FakeIdentityAuthMiddleware::class);
        $this->app['router']->aliasMiddleware('identity.require-permission', FakeRequirePermissionMiddleware::class);
    }

    private function mockLedgerAndFees(): void
    {
        $this->mock(LedgerModuleContract::class, function ($mock): void {
            $mock->shouldReceive('registerAccount')->andReturn(true)->byDefault();
            $mock->shouldReceive('postEntry')->andReturn((string) Uuid::uuid7())->byDefault();
        });
        $this->mock(FeesModuleContract::class, function ($mock): void {
            $mock->shouldReceive('reverseFee')->andReturn(true)->byDefault();
        });
    }

    public function test_admin_can_issue_full_refund_on_approved_dispute(): void
    {
        $this->mockLedgerAndFees();

        $adminId = (string) Uuid::uuid7();
        $customerId = (string) Uuid::uuid7();
        $dispute = $this->createDispute((string) Uuid::uuid7(), $customerId, 'approved');

        $response = $this->postJson("/v1/disputes/{$dispute->id}/refund", [
            'amount_minor' => 10_000,
            'currency' => 'KES',
        ], [
            'X-Test-User-Id' => $adminId,
            'X-Test-Actor-Type' => 'admin',
            'X-Test-Permissions' => 'dispute.refund',
            'Idempotency-Key' => (string) Uuid::uuid7(),
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'refunded');
        $this->assertNotNull($response->json('data.dispute_refunded_at'));
    }

    public function test_partial_refund_with_justification_succeeds(): void
    {
        $this->mockLedgerAndFees();

        $adminId = (string) Uuid::uuid7();
        $customerId = (string) Uuid::uuid7();
        $dispute = $this->createDispute((string) Uuid::uuid7(), $customerId, 'approved');

        $response = $this->postJson("/v1/disputes/{$dispute->id}/refund", [
            'amount_minor' => 5_000,
            'currency' => 'KES',
            'partial_refund_justification' => 'Customer received partial goods; partial refund agreed.',
        ], [
            'X-Test-User-Id' => $adminId,
            'X-Test-Actor-Type' => 'admin',
            'X-Test-Permissions' => 'dispute.refund',
            'Idempotency-Key' => (string) Uuid::uuid7(),
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'refunded');
    }

    public function test_partial_refund_with_empty_justification_returns_422(): void
    {
        $adminId = (string) Uuid::uuid7();
        $customerId = (string) Uuid::uuid7();
        $dispute = $this->createDispute((string) Uuid::uuid7(), $customerId, 'approved');

        $response = $this->postJson("/v1/disputes/{$dispute->id}/refund", [
            'amount_minor' => 5_000,
            'currency' => 'KES',
            'partial_refund_justification' => '',
        ], [
            'X-Test-User-Id' => $adminId,
            'X-Test-Actor-Type' => 'admin',
            'X-Test-Permissions' => 'dispute.refund',
            'Idempotency-Key' => (string) Uuid::uuid7(),
        ]);

        $response->assertStatus(422);
    }

    public function test_refund_on_non_approved_dispute_returns_422(): void
    {
        $adminId = (string) Uuid::uuid7();
        $customerId = (string) Uuid::uuid7();
        $dispute = $this->createDispute((string) Uuid::uuid7(), $customerId, 'under_investigation');

        $response = $this->postJson("/v1/disputes/{$dispute->id}/refund", [
            'amount_minor' => 10_000,
            'currency' => 'KES',
        ], [
            'X-Test-User-Id' => $adminId,
            'X-Test-Actor-Type' => 'admin',
            'X-Test-Permissions' => 'dispute.refund',
            'Idempotency-Key' => (string) Uuid::uuid7(),
        ]);

        $response->assertStatus(422);
    }

    public function test_refund_is_idempotent(): void
    {
        $this->mockLedgerAndFees();

        $adminId = (string) Uuid::uuid7();
        $customerId = (string) Uuid::uuid7();
        $dispute = $this->createDispute((string) Uuid::uuid7(), $customerId, 'approved');
        $idempotencyKey = (string) Uuid::uuid7();

        $payload = ['amount_minor' => 10_000, 'currency' => 'KES'];
        $headers = [
            'X-Test-User-Id' => $adminId,
            'X-Test-Actor-Type' => 'admin',
            'X-Test-Permissions' => 'dispute.refund',
            'Idempotency-Key' => $idempotencyKey,
        ];

        $r1 = $this->postJson("/v1/disputes/{$dispute->id}/refund", $payload, $headers);
        $r2 = $this->postJson("/v1/disputes/{$dispute->id}/refund", $payload, $headers);

        $r1->assertOk();
        $r2->assertOk();
        $this->assertSame($r1->json('data.id'), $r2->json('data.id'));
    }

    public function test_ledger_failure_does_not_change_dispute_status(): void
    {
        $this->mock(LedgerModuleContract::class, function ($mock): void {
            $mock->shouldReceive('registerAccount')->andReturn(true)->byDefault();
            $mock->shouldReceive('postEntry')
                ->andThrow(new \RuntimeException('Ledger service unavailable'));
        });
        $this->mock(FeesModuleContract::class, function ($mock): void {
            $mock->shouldReceive('reverseFee')->andReturn(true)->byDefault();
        });

        $adminId = (string) Uuid::uuid7();
        $customerId = (string) Uuid::uuid7();
        $dispute = $this->createDispute((string) Uuid::uuid7(), $customerId, 'approved');

        try {
            $this->postJson("/v1/disputes/{$dispute->id}/refund", [
                'amount_minor' => 10_000,
                'currency' => 'KES',
            ], [
                'X-Test-User-Id' => $adminId,
                'X-Test-Actor-Type' => 'admin',
                'X-Test-Permissions' => 'dispute.refund',
                'Idempotency-Key' => (string) Uuid::uuid7(),
            ]);
        } catch (\Throwable) {
            // Exception is expected from the ledger failure
        }

        $dispute->refresh();
        $this->assertSame('approved', $dispute->status->value);
    }

    public function test_two_approver_refund_flow(): void
    {
        $this->mockLedgerAndFees();

        config(['support.dispute.refund.require_second_approval' => true]);

        $admin1Id = (string) Uuid::uuid7();
        $admin2Id = (string) Uuid::uuid7();
        $customerId = (string) Uuid::uuid7();
        $dispute = $this->createDispute((string) Uuid::uuid7(), $customerId, 'approved');

        // First admin initiates refund → pending confirmation, no ledger posted yet
        $r1 = $this->postJson("/v1/disputes/{$dispute->id}/refund", [
            'amount_minor' => 10_000,
            'currency' => 'KES',
        ], [
            'X-Test-User-Id' => $admin1Id,
            'X-Test-Actor-Type' => 'admin',
            'X-Test-Permissions' => 'dispute.refund',
            'Idempotency-Key' => (string) Uuid::uuid7(),
        ]);

        $r1->assertOk();
        $this->assertTrue($r1->json('data.refund_pending_confirmation'));
        $this->assertSame('approved', $r1->json('data.status'));

        // Same admin cannot confirm their own refund
        $sameActorConfirm = $this->patchJson("/v1/disputes/{$dispute->id}/confirm-refund", [], [
            'X-Test-User-Id' => $admin1Id,
            'X-Test-Actor-Type' => 'admin',
            'X-Test-Permissions' => 'dispute.refund',
            'Idempotency-Key' => (string) Uuid::uuid7(),
        ]);
        $sameActorConfirm->assertForbidden();

        // Second admin confirms → ledger posted, status=refunded
        $r2 = $this->patchJson("/v1/disputes/{$dispute->id}/confirm-refund", [], [
            'X-Test-User-Id' => $admin2Id,
            'X-Test-Actor-Type' => 'admin',
            'X-Test-Permissions' => 'dispute.refund',
            'Idempotency-Key' => (string) Uuid::uuid7(),
        ]);

        $r2->assertOk();
        $r2->assertJsonPath('data.status', 'refunded');
        $this->assertNotNull($r2->json('data.dispute_refunded_at'));
    }
}
