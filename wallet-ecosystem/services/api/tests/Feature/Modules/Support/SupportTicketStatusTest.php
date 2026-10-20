<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Support;

use App\Modules\Support\Domain\Enums\TicketStatus;
use App\Modules\Support\Infrastructure\Models\SupportTicketModel;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Support\FakeIdentityAuthMiddleware;
use Tests\Support\PlatformTestCase;

final class SupportTicketStatusTest extends PlatformTestCase
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
        $ticket->subject       = 'Test ticket';
        $ticket->description   = 'Description';
        $ticket->category      = 'transaction';
        $ticket->priority      = 'medium';
        $ticket->status        = $status;
        $ticket->correlation_id = 'test-corr-' . uniqid();
        $ticket->save();

        return $ticket;
    }

    public function test_admin_can_resolve_in_progress_ticket(): void
    {
        $customerId = (string) Uuid::uuid7();
        $adminId    = (string) Uuid::uuid7();
        $ticket     = $this->createTicket($customerId, TicketStatus::InProgress);

        $response = $this->patchJson("/v1/support/tickets/{$ticket->id}/status", [
            'status'          => 'resolved',
            'resolution_note' => 'Issue resolved after investigation.',
        ], [
            'X-Test-User-Id'    => $adminId,
            'X-Test-Actor-Type' => 'admin',
            'X-Test-Permissions' => 'support.create',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'resolved');
    }

    public function test_invalid_status_transition_returns_422(): void
    {
        $customerId = (string) Uuid::uuid7();
        $adminId    = (string) Uuid::uuid7();
        // resolved → in_progress is invalid; no resolution_note needed for in_progress
        $ticket     = $this->createTicket($customerId, TicketStatus::Resolved);

        $response = $this->patchJson("/v1/support/tickets/{$ticket->id}/status", [
            'status' => 'in_progress',
        ], [
            'X-Test-User-Id'    => $adminId,
            'X-Test-Actor-Type' => 'admin',
            'X-Test-Permissions' => 'support.create',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'INVALID_TICKET_STATUS_TRANSITION');
    }
}
