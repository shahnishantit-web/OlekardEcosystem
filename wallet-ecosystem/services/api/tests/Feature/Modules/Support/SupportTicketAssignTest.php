<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Support;

use App\Modules\Support\Domain\Enums\TicketStatus;
use App\Modules\Support\Infrastructure\Models\SupportTicketModel;
use Ramsey\Uuid\Uuid;
use Tests\Support\FakeIdentityAuthMiddleware;
use Tests\Support\PlatformTestCase;

final class SupportTicketAssignTest extends PlatformTestCase
{
    protected $connectionsToTransact = [null, 'identity', 'platform'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['router']->aliasMiddleware('identity.auth', FakeIdentityAuthMiddleware::class);
        $this->app['router']->aliasMiddleware('identity.require-permission', \Tests\Support\FakeRequirePermissionMiddleware::class);
        $this->mock(\App\Modules\Identity\Domain\Contracts\IdentityModuleContract::class, function ($mock): void {
            $mock->shouldReceive('hasPermission')->andReturn(true)->byDefault();
        });
    }

    private function createTicket(string $reporterId): SupportTicketModel
    {
        $seq = \Illuminate\Support\Facades\DB::connection('platform')
            ->selectOne("SELECT nextval('support.ticket_number_seq') AS seq");

        $ticket                = new SupportTicketModel();
        $ticket->id            = (string) Uuid::uuid7();
        $ticket->ticket_number = sprintf('TKT-%06d', $seq->seq);
        $ticket->reporter_id   = $reporterId;
        $ticket->reporter_type = 'customer';
        $ticket->subject       = 'Test ticket';
        $ticket->description   = 'Description';
        $ticket->category      = 'transaction';
        $ticket->priority      = 'medium';
        $ticket->status        = TicketStatus::Open;
        $ticket->correlation_id = 'test-corr-' . uniqid();
        $ticket->save();

        return $ticket;
    }

    public function test_admin_can_assign_ticket(): void
    {
        $customerId = (string) Uuid::uuid7();
        $adminId    = (string) Uuid::uuid7();
        $agentId    = (string) Uuid::uuid7();
        $ticket     = $this->createTicket($customerId);

        $response = $this->patchJson("/v1/support/tickets/{$ticket->id}/assign", [
            'assignee_id' => $agentId,
        ], [
            'X-Test-User-Id'    => $adminId,
            'X-Test-Actor-Type' => 'admin',
            'X-Test-Permissions' => 'support.assign',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.assignee_id', $agentId);
    }

    public function test_assign_closed_ticket_returns_422(): void
    {
        $customerId = (string) Uuid::uuid7();
        $adminId    = (string) Uuid::uuid7();
        $ticket     = $this->createTicket($customerId);
        $ticket->status = TicketStatus::Closed;
        $ticket->save();

        $response = $this->patchJson("/v1/support/tickets/{$ticket->id}/assign", [
            'assignee_id' => (string) Uuid::uuid7(),
        ], [
            'X-Test-User-Id'    => $adminId,
            'X-Test-Actor-Type' => 'admin',
            'X-Test-Permissions' => 'support.assign',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'TICKET_IS_CLOSED');
    }

    public function test_assign_nonexistent_ticket_returns_404(): void
    {
        $adminId = (string) Uuid::uuid7();

        $response = $this->patchJson('/v1/support/tickets/' . Uuid::uuid7() . '/assign', [
            'assignee_id' => (string) Uuid::uuid7(),
        ], [
            'X-Test-User-Id'    => $adminId,
            'X-Test-Actor-Type' => 'admin',
            'X-Test-Permissions' => 'support.assign',
        ]);

        $response->assertStatus(404);
    }
}
