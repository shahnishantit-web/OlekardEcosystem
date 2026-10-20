<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Support;

use Ramsey\Uuid\Uuid;
use Tests\Support\PlatformTestCase;
use Tests\Support\FakeIdentityAuthMiddleware;

final class SupportTicketCreateTest extends PlatformTestCase
{
    protected $connectionsToTransact = [null, 'identity', 'platform'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['router']->aliasMiddleware('identity.auth', FakeIdentityAuthMiddleware::class);
        $this->app['router']->aliasMiddleware('identity.require-permission', \Tests\Support\FakeRequirePermissionMiddleware::class);
    }

    public function test_customer_can_create_support_ticket(): void
    {
        $customerId = (string) Uuid::uuid7();

        $response = $this->postJson('/v1/support/tickets', [
            'subject'     => 'My transaction is missing',
            'description' => 'I sent money but it never arrived.',
            'category'    => 'transaction',
            'priority'    => 'medium',
        ], [
            'X-Test-User-Id'    => $customerId,
            'X-Test-Actor-Type' => 'customer',
            'X-Test-Permissions' => 'support.create',
            'Idempotency-Key'   => (string) Uuid::uuid7(),
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.reporter_id', $customerId);
        $response->assertJsonStructure(['data' => ['id', 'ticket_number', 'status']]);
        $this->assertStringStartsWith('TKT-', $response->json('data.ticket_number'));
    }

    public function test_create_ticket_is_idempotent(): void
    {
        $customerId     = (string) Uuid::uuid7();
        $idempotencyKey = (string) Uuid::uuid7();

        $payload = [
            'subject'     => 'Idempotency test',
            'description' => 'Create same ticket twice.',
            'category'    => 'transaction',
            'priority'    => 'medium',
        ];

        $headers = [
            'X-Test-User-Id'    => $customerId,
            'X-Test-Actor-Type' => 'customer',
            'X-Test-Permissions' => 'support.create',
            'Idempotency-Key'   => $idempotencyKey,
        ];

        $first  = $this->postJson('/v1/support/tickets', $payload, $headers);
        $second = $this->postJson('/v1/support/tickets', $payload, $headers);

        $first->assertStatus(201);
        $second->assertStatus(200);
        $this->assertEquals($first->json('data.id'), $second->json('data.id'));
    }

    public function test_create_ticket_requires_authentication(): void
    {
        $response = $this->postJson('/v1/support/tickets', [
            'subject'     => 'Should fail',
            'description' => 'No auth.',
            'category'    => 'transaction',
            'priority'    => 'medium',
        ]);

        $response->assertStatus(401);
    }

    public function test_create_ticket_validates_required_fields(): void
    {
        $response = $this->postJson('/v1/support/tickets', [], [
            'X-Test-User-Id'    => (string) Uuid::uuid7(),
            'X-Test-Actor-Type' => 'customer',
            'X-Test-Permissions' => 'support.create',
            'Idempotency-Key'   => (string) Uuid::uuid7(),
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $fields = array_column($response->json('error.details') ?? [], 'field');
        $this->assertContains('subject', $fields);
        $this->assertContains('description', $fields);
        $this->assertContains('category', $fields);
    }

    public function test_can_list_own_tickets(): void
    {
        $customerId = (string) Uuid::uuid7();

        $this->postJson('/v1/support/tickets', [
            'subject'     => 'Ticket 1',
            'description' => 'First ticket',
            'category'    => 'transaction',
            'priority'    => 'medium',
        ], [
            'X-Test-User-Id'    => $customerId,
            'X-Test-Actor-Type' => 'customer',
            'X-Test-Permissions' => 'support.create',
            'Idempotency-Key'   => (string) Uuid::uuid7(),
        ]);

        $response = $this->getJson('/v1/support/tickets', [
            'X-Test-User-Id'    => $customerId,
            'X-Test-Actor-Type' => 'customer',
            'X-Test-Permissions' => 'support.create',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['data' => ['items', 'meta']]);
        $this->assertCount(1, $response->json('data.items'));
    }
}
