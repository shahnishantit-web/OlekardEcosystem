<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Support\Concerns;

use App\Modules\Support\Domain\Enums\DisputeStatus;
use App\Modules\Support\Domain\Enums\TicketCategory;
use App\Modules\Support\Domain\Enums\TicketPriority;
use App\Modules\Support\Domain\Enums\TicketStatus;
use App\Modules\Support\Infrastructure\Models\DisputeModel;
use App\Modules\Support\Infrastructure\Models\SupportTicketModel;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

trait CreatesDisputeFixtures
{
    private function createTicket(
        string $reporterId,
        string $status = 'open',
    ): SupportTicketModel {
        $seq = DB::connection('platform')
            ->selectOne("SELECT nextval('support.ticket_number_seq') AS seq");

        $ticket                = new SupportTicketModel();
        $ticket->id            = (string) Uuid::uuid7();
        $ticket->ticket_number = sprintf('TKT-%06d', $seq->seq);
        $ticket->reporter_id   = $reporterId;
        $ticket->reporter_type = 'customer';
        $ticket->subject       = 'Test ticket subject';
        $ticket->description   = 'Test ticket description';
        $ticket->category       = TicketCategory::Transaction;
        $ticket->priority       = TicketPriority::Medium;
        $ticket->status         = TicketStatus::from($status);
        $ticket->correlation_id = (string) Uuid::uuid7();
        $ticket->save();

        return $ticket;
    }

    private function createDispute(
        string  $transactionId,
        string  $reporterId,
        string  $status = 'raised',
        ?string $ticketId = null,
        string  $currency = 'KES',
    ): DisputeModel {
        $seq = DB::connection('platform')
            ->selectOne("SELECT nextval('support.dispute_number_seq') AS seq");

        $dispute                              = new DisputeModel();
        $dispute->id                          = (string) Uuid::uuid7();
        $dispute->dispute_number              = sprintf('DSP-%06d', $seq->seq);
        $dispute->transaction_id              = $transactionId;
        $dispute->ticket_id                   = $ticketId;
        $dispute->reporter_id                 = $reporterId;
        $dispute->reporter_type               = 'customer';
        $dispute->description                 = 'Test dispute description';
        $dispute->status                      = DisputeStatus::from($status);
        $dispute->escalated                   = false;
        $dispute->refund_pending_confirmation = false;
        $dispute->currency_code               = $currency;
        $dispute->correlation_id              = (string) Uuid::uuid7();
        $dispute->save();

        return $dispute;
    }
}
