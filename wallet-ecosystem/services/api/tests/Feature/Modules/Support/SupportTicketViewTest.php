<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Support;

use App\Modules\Support\Domain\Enums\TicketStatus;
use App\Modules\Support\Infrastructure\Models\SupportTicketModel;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Support\FakeIdentityAuthMiddleware;
use Tests\Support\PlatformTestCase;

final class SupportTicketViewTest extends PlatformTestCase
{
    protected $connectionsToTransact = [null, 'identity', 'platform'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['router']->aliasMiddleware('identity.auth', FakeIdentityAuthMiddleware::class);
        $this->app['router']->aliasMiddleware('identity.require-permission', \Tests\Support\FakeRequirePermissionMiddleware::class);
    }

    private function createTicket(string $reporterId, TicketStatus $status = TicketStatus::Open): SupportTicketModel
    {
        $seq = DB::connection('platform')->selectOne("SELECT nextval('support.ticket_number_seq') AS seq");

        $ticket                = new SupportTicketModel();
        $ticket->id            = (string) Uuid::uuid7();
        $ticket->ticket_number = sprintf('TKT-%06d', $seq->seq);
        $ticket->reporter_id   = $reporterId;
        $ticket->reporter_type = 'customer';
        $ticket->subject       = 'Test subject';
        $ticket->description   = 'Test description';
        $ticket->category      = 'transaction';
        $ticket->priority      = 'medium';
        $ticket->status        = $status;
        $ticket->correlation_id = 'test-corr-' . uniqid();
        $ticket->save();

        return $ticket;
    }

    public function test_reporter_can_view_own_ticket_list(): void
    {
        $customerId = (string) Uuid::uuid7();
        $this->createTicket($customerId);
        $this->createTicket($customerId);

        $response = $this->getJson('/v1/support/tickets', [
            'X-Test-User-Id'    => $customerId,
            'X-Test-Actor-Type' => 'customer',
            'X-Test-Permissions' => 'support.create',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['data' => ['items', 'meta']]);
        $this->assertCount(2, $response->json('data.items'));
    }

    public function test_reporter_cannot_see_other_reporters_tickets(): void
    {
        $customerId = (string) Uuid::uuid7();
        $otherId    = (string) Uuid::uuid7();
        $this->createTicket($otherId);

        $response = $this->getJson('/v1/support/tickets', [
            'X-Test-User-Id'    => $customerId,
            'X-Test-Actor-Type' => 'customer',
            'X-Test-Permissions' => 'support.create',
        ]);

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data.items'));
    }

    public function test_admin_sees_all_tickets(): void
    {
        $customer1 = (string) Uuid::uuid7();
        $customer2 = (string) Uuid::uuid7();
        $adminId   = (string) Uuid::uuid7();
        $this->createTicket($customer1);
        $this->createTicket($customer2);

        $response = $this->getJson('/v1/support/tickets', [
            'X-Test-User-Id'    => $adminId,
            'X-Test-Actor-Type' => 'admin',
            'X-Test-Permissions' => 'support.create',
        ]);

        $response->assertStatus(200);
        $this->assertGreaterThanOrEqual(2, count($response->json('data.items')));
    }

    public function test_admin_can_filter_by_status(): void
    {
        $customer1 = (string) Uuid::uuid7();
        $adminId   = (string) Uuid::uuid7();
        $this->createTicket($customer1, TicketStatus::Open);
        $this->createTicket($customer1, TicketStatus::Resolved);

        $response = $this->getJson('/v1/support/tickets?status=open', [
            'X-Test-User-Id'    => $adminId,
            'X-Test-Actor-Type' => 'admin',
            'X-Test-Permissions' => 'support.create',
        ]);

        $response->assertStatus(200);
        foreach ($response->json('data.items') as $item) {
            $this->assertEquals('open', $item['status']);
        }
    }

    public function test_reporter_gets_403_viewing_another_reporters_ticket(): void
    {
        $ownerId   = (string) Uuid::uuid7();
        $otherId   = (string) Uuid::uuid7();
        $ticket    = $this->createTicket($ownerId);

        $response = $this->getJson("/v1/support/tickets/{$ticket->id}", [
            'X-Test-User-Id'    => $otherId,
            'X-Test-Actor-Type' => 'customer',
            'X-Test-Permissions' => 'support.create',
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'PERMISSION_DENIED');
    }

    public function test_pagination_cursor_round_trip(): void
    {
        $customerId = (string) Uuid::uuid7();
        for ($i = 0; $i < 3; $i++) {
            $this->createTicket($customerId);
        }

        $first = $this->getJson('/v1/support/tickets?per_page=2', [
            'X-Test-User-Id'    => $customerId,
            'X-Test-Actor-Type' => 'customer',
            'X-Test-Permissions' => 'support.create',
        ]);

        $first->assertStatus(200);
        $this->assertCount(2, $first->json('data.items'));
        $this->assertNotNull($first->json('data.meta.next_cursor'));

        $cursor = $first->json('data.meta.next_cursor');
        $second = $this->getJson("/v1/support/tickets?per_page=2&cursor={$cursor}", [
            'X-Test-User-Id'    => $customerId,
            'X-Test-Actor-Type' => 'customer',
            'X-Test-Permissions' => 'support.create',
        ]);

        $second->assertStatus(200);
        $this->assertCount(1, $second->json('data.items'));
    }
}
