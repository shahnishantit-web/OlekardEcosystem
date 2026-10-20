<?php

declare(strict_types=1);

namespace App\Modules\Support\Application\Services;

use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Support\Domain\Enums\TicketStatus;
use App\Modules\Support\Domain\Events\TicketNoteAddedEvent;
use App\Modules\Support\Domain\Exceptions\TicketAlreadyClosedException;
use App\Modules\Support\Domain\Exceptions\TicketForbiddenException;
use App\Modules\Support\Domain\Exceptions\TicketNotFoundException;
use App\Modules\Support\Infrastructure\Models\SupportTicketModel;
use App\Modules\Support\Infrastructure\Models\TicketNoteModel;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class SupportTicketNoteService
{
    public function __construct(private readonly EventPublisherInterface $events) {}

    public function addNote(
        string $ticketId,
        string $authorId,
        string $authorRole,
        string $content,
        bool   $isInternal,
        string $correlationId,
    ): TicketNoteModel {
        $ticket = SupportTicketModel::find($ticketId);

        if ($ticket === null) {
            throw new TicketNotFoundException("Support ticket [{$ticketId}] not found.");
        }

        if ($ticket->status === TicketStatus::Closed) {
            throw new TicketAlreadyClosedException("Cannot add a note to closed ticket [{$ticketId}].");
        }

        if ($authorRole !== 'admin' && $ticket->reporter_id !== $authorId) {
            throw new TicketForbiddenException("Access denied to ticket [{$ticketId}].");
        }

        if ($isInternal && $authorRole !== 'admin') {
            $isInternal = false;
        }

        return DB::connection('platform')->transaction(function () use (
            $ticket, $authorId, $authorRole, $content, $isInternal, $correlationId
        ): TicketNoteModel {
            $note              = new TicketNoteModel();
            $note->id          = (string) Uuid::uuid7();
            $note->ticket_id   = $ticket->id;
            $note->author_id   = $authorId;
            $note->author_type = $authorRole;
            $note->content     = $content;
            $note->is_internal = $isInternal;
            $note->created_at  = now();
            $note->save();

            $this->events->publish(new TicketNoteAddedEvent(
                ticketId:      $ticket->id,
                noteId:        $note->id,
                authorId:      $authorId,
                isInternal:    $isInternal,
                reporterId:    $ticket->reporter_id,
                occurredAt:    now()->toIso8601String(),
                correlationId: $correlationId,
            ));

            return $note;
        });
    }
}
