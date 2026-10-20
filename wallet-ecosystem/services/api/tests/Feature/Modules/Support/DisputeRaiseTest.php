<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Support;

use Tests\Support\FakeRequirePermissionMiddleware;
use App\Modules\Transaction\Domain\Contracts\TransactionModuleContract;
use Ramsey\Uuid\Uuid;
use Tests\Feature\Modules\Support\Concerns\CreatesDisputeFixtures;
use Tests\Support\FakeIdentityAuthMiddleware;
use Tests\Support\PlatformTestCase;

final class DisputeRaiseTest extends PlatformTestCase
{
    use CreatesDisputeFixtures;

    protected $connectionsToTransact = [null, 'identity', 'platform'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['router']->aliasMiddleware('identity.auth', FakeIdentityAuthMiddleware::class);
        $this->app['router']->aliasMiddleware('identity.require-permission', FakeRequirePermissionMiddleware::class);
    }

    public function test_customer_can_raise_dispute_for_own_ticket(): void
    {
        $this->mock(TransactionModuleContract::class, function ($mock): void {
            $mock->shouldReceive('status')->andReturn('completed');
        });

        $customerId = (string) Uuid::uuid7();
        $txId       = (string) Uuid::uuid7();
        $ticket     = $this->createTicket($customerId);

        $response = $this->postJson('/v1/disputes', [
            'ticket_id'      => $ticket->id,
            'transaction_id' => $txId,
        ], [
            'X-Test-User-Id'     => $customerId,
            'X-Test-Actor-Type'  => 'customer',
            'X-Test-Permissions' => 'dispute.raise',
            'Idempotency-Key'    => (string) Uuid::uuid7(),
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'raised');
        $response->assertJsonPath('data.transaction_id', $txId);
        $response->assertJsonPath('data.reporter_id', $customerId);
        $this->assertStringStartsWith('DSP-', $response->json('data.dispute_number'));
    }

    public function test_raise_dispute_is_idempotent(): void
    {
        $this->mock(TransactionModuleContract::class, function ($mock): void {
            $mock->shouldReceive('status')->andReturn('completed');
        });

        $customerId      = (string) Uuid::uuid7();
        $txId            = (string) Uuid::uuid7();
        $ticket          = $this->createTicket($customerId);
        $idempotencyKey  = (string) Uuid::uuid7();

        $payload = [
            'ticket_id'      => $ticket->id,
            'transaction_id' => $txId,
        ];
        $headers = [
            'X-Test-User-Id'     => $customerId,
            'X-Test-Actor-Type'  => 'customer',
            'X-Test-Permissions' => 'dispute.raise',
            'Idempotency-Key'    => $idempotencyKey,
        ];

        $r1 = $this->postJson('/v1/disputes', $payload, $headers);
        $r2 = $this->postJson('/v1/disputes', $payload, $headers);

        $r1->assertCreated();
        $r2->assertOk();
        $this->assertSame($r1->json('data.id'), $r2->json('data.id'));
    }

    public function test_raises_409_when_active_dispute_already_exists_for_transaction(): void
    {
        $this->mock(TransactionModuleContract::class, function ($mock): void {
            $mock->shouldReceive('status')->andReturn('completed');
        });

        $customerId = (string) Uuid::uuid7();
        $txId       = (string) Uuid::uuid7();
        $ticket1    = $this->createTicket($customerId);
        $ticket2    = $this->createTicket($customerId);

        // Seed an existing active dispute for this transaction
        $this->createDispute($txId, $customerId, 'raised', $ticket1->id);

        $response = $this->postJson('/v1/disputes', [
            'ticket_id'      => $ticket2->id,
            'transaction_id' => $txId,
        ], [
            'X-Test-User-Id'     => $customerId,
            'X-Test-Actor-Type'  => 'customer',
            'X-Test-Permissions' => 'dispute.raise',
            'Idempotency-Key'    => (string) Uuid::uuid7(),
        ]);

        $response->assertStatus(409);
    }

    public function test_raises_422_when_transaction_not_in_terminal_state(): void
    {
        $this->mock(TransactionModuleContract::class, function ($mock): void {
            $mock->shouldReceive('status')->andReturn('pending');
        });

        $customerId = (string) Uuid::uuid7();
        $ticket     = $this->createTicket($customerId);

        $response = $this->postJson('/v1/disputes', [
            'ticket_id'      => $ticket->id,
            'transaction_id' => (string) Uuid::uuid7(),
        ], [
            'X-Test-User-Id'     => $customerId,
            'X-Test-Actor-Type'  => 'customer',
            'X-Test-Permissions' => 'dispute.raise',
            'Idempotency-Key'    => (string) Uuid::uuid7(),
        ]);

        $response->assertStatus(422);
    }

    public function test_raises_403_when_reporter_does_not_own_ticket(): void
    {
        $this->mock(TransactionModuleContract::class, function ($mock): void {
            $mock->shouldReceive('status')->andReturn('completed');
        });

        $ownerId  = (string) Uuid::uuid7();
        $otherId  = (string) Uuid::uuid7();
        $ticket   = $this->createTicket($ownerId);

        $response = $this->postJson('/v1/disputes', [
            'ticket_id'      => $ticket->id,
            'transaction_id' => (string) Uuid::uuid7(),
        ], [
            'X-Test-User-Id'     => $otherId,
            'X-Test-Actor-Type'  => 'customer',
            'X-Test-Permissions' => 'dispute.raise',
            'Idempotency-Key'    => (string) Uuid::uuid7(),
        ]);

        $response->assertForbidden();
    }
}
