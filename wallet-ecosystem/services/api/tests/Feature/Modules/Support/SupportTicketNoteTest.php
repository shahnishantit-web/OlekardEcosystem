<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Support;

use App\Modules\Support\Domain\Enums\TicketStatus;
use App\Modules\Support\Infrastructure\Models\SupportTicketModel;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Support\FakeIdentityAuthMiddleware;
use Tests\Support\PlatformTestCase;

final class SupportTicketNoteTest extends PlatformTestCase
{
    protected $connectionsToTransact = [null, 'identity', 'platform'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['router']->aliasMiddleware('identity.auth', FakeIdentityAuthMiddleware::class);
        $this->app['router']->aliasMiddleware('identity.require-permission', \Tests\Support\FakeRequirePermissionMiddleware::class);
    }

    private function createTicket(string $reporterId): SupportTicketModel
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
        $ticket->status        = TicketStatus::Open;
        $ticket->correlation_id = 'test-corr-' . uniqid();
        $ticket->save();

        return $ticket;
    }

    public function test_reporter_can_add_note(): void
    {
        $customerId = (string) Uuid::uuid7();
        $ticket     = $this->createTicket($customerId);

        $response = $this->postJson("/v1/support/tickets/{$ticket->id}/notes", [
            'content' => 'Please help me.',
        ], [
            'X-Test-User-Id'    => $customerId,
            'X-Test-Actor-Type' => 'customer',
            'X-Test-Permissions' => 'support.create',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.author_id', $customerId);
        $response->assertJsonPath('data.is_internal', false);
    }

    public function test_admin_can_add_internal_note(): void
    {
        $customerId = (string) Uuid::uuid7();
        $adminId    = (string) Uuid::uuid7();
        $ticket     = $this->createTicket($customerId);

        $response = $this->postJson("/v1/support/tickets/{$ticket->id}/notes", [
            'content'     => 'Internal observation.',
            'is_internal' => true,
        ], [
            'X-Test-User-Id'    => $adminId,
            'X-Test-Actor-Type' => 'admin',
            'X-Test-Permissions' => 'support.create',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.is_internal', true);
    }

    public function test_non_reporter_customer_cannot_add_note(): void
    {
        $reporterId   = (string) Uuid::uuid7();
        $otherId      = (string) Uuid::uuid7();
        $ticket       = $this->createTicket($reporterId);

        $response = $this->postJson("/v1/support/tickets/{$ticket->id}/notes", [
            'content' => 'I should not be able to add this.',
        ], [
            'X-Test-User-Id'    => $otherId,
            'X-Test-Actor-Type' => 'customer',
            'X-Test-Permissions' => 'support.create',
        ]);

        $response->assertStatus(403);
    }
}
