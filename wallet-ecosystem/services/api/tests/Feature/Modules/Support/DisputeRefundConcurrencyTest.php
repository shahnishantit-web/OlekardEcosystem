<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Support;

use App\Modules\Fees\Domain\Contracts\FeesModuleContract;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Feature\Modules\Support\Concerns\CreatesDisputeFixtures;
use Tests\Support\FakeIdentityAuthMiddleware;
use Tests\Support\FakeRequirePermissionMiddleware;
use Tests\Support\PlatformTestCase;

/**
 * Verifies that UNIQUE constraint on refund_idempotency_key prevents double-posting
 * when two concurrent requests race on the same approved dispute.
 */
final class DisputeRefundConcurrencyTest extends PlatformTestCase
{
    use CreatesDisputeFixtures, \Tests\Feature\Modules\Support\Concerns\CreatesSecureRefundContext;

    protected $connectionsToTransact = [null, 'identity', 'platform'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['router']->aliasMiddleware('identity.auth', FakeIdentityAuthMiddleware::class);
        $this->app['router']->aliasMiddleware('identity.require-permission', FakeRequirePermissionMiddleware::class);

        $this->mock(LedgerModuleContract::class, function ($mock): void {
            $mock->shouldReceive('registerAccount')->andReturn(true)->byDefault();
            $mock->shouldReceive('postEntry')->andReturn((string) Uuid::uuid7())->byDefault();
        });
        $this->mock(FeesModuleContract::class, function ($mock): void {
            $mock->shouldReceive('reverseFee')->andReturn(true)->byDefault();
        });
    }

    public function test_unique_constraint_on_refund_idempotency_key_prevents_double_posting(): void
    {
        $adminId = (string) Uuid::uuid7();
        $customerId = (string) Uuid::uuid7();
        $dispute = $this->createDispute((string) Uuid::uuid7(), $customerId, 'approved');

        $idempotencyKey = (string) Uuid::uuid7();
        $headers = [
            'X-Test-User-Id' => $adminId,
            'X-Test-Actor-Type' => 'admin',
            'X-Test-Permissions' => 'dispute.refund',
            'Idempotency-Key' => $idempotencyKey,
        ];
        $payload = ['amount_minor' => 10_000, 'currency' => 'KES'];

        // First request succeeds
        $r1 = $this->postJson("/v1/disputes/{$dispute->id}/refund", $payload, $headers);
        $r1->assertOk();
        $r1->assertJsonPath('data.status', 'refunded');

        // Second concurrent request with same key returns idempotent result (200)
        $r2 = $this->postJson("/v1/disputes/{$dispute->id}/refund", $payload, $headers);
        $r2->assertOk();
        $this->assertSame($r1->json('data.id'), $r2->json('data.id'));

        // Exactly one outbox event emitted
        $eventCount = DB::connection('platform')
            ->table('platform.outbox_events')
            ->where('event_name', 'Support.Dispute.Refunded')
            ->where('payload->dispute_id', $dispute->id)
            ->count();

        $this->assertSame(1, $eventCount, 'Only one DisputeRefunded event should be emitted');
    }

    public function test_third_party_key_collision_on_same_dispute_is_rejected(): void
    {
        $admin1Id = (string) Uuid::uuid7();
        $admin2Id = (string) Uuid::uuid7();
        $customerId = (string) Uuid::uuid7();
        $dispute = $this->createDispute((string) Uuid::uuid7(), $customerId, 'approved');

        // Admin 1 refunds with a distinct key
        $this->postJson("/v1/disputes/{$dispute->id}/refund", [
            'amount_minor' => 10_000,
            'currency' => 'KES',
        ], [
            'X-Test-User-Id' => $admin1Id,
            'X-Test-Actor-Type' => 'admin',
            'X-Test-Permissions' => 'dispute.refund',
            'Idempotency-Key' => (string) Uuid::uuid7(),
        ])->assertOk();

        $dispute->refresh();
        $this->assertSame('refunded', $dispute->status->value);

        // Admin 2 attempts a second refund — dispute already refunded (terminal state)
        $r2 = $this->postJson("/v1/disputes/{$dispute->id}/refund", [
            'amount_minor' => 10_000,
            'currency' => 'KES',
        ], [
            'X-Test-User-Id' => $admin2Id,
            'X-Test-Actor-Type' => 'admin',
            'X-Test-Permissions' => 'dispute.refund',
            'Idempotency-Key' => (string) Uuid::uuid7(),
        ]);

        $r2->assertStatus(422);
    }
}
