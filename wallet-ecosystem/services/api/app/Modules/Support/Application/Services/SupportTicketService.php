<?php

declare(strict_types=1);

namespace App\Modules\Support\Application\Services;

use App\Modules\Support\Domain\Enums\DisputeStatus;
use App\Modules\Support\Domain\Enums\TicketCategory;
use App\Modules\Support\Domain\Enums\TicketPriority;
use App\Modules\Support\Domain\Enums\TicketStatus;
use App\Modules\Support\Domain\Events\SupportTicketAssignedEvent;
use App\Modules\Support\Domain\Events\SupportTicketCreatedEvent;
use App\Modules\Support\Domain\Events\SupportTicketReassignedEvent;
use App\Modules\Support\Domain\Events\SupportTicketReopenedEvent;
use App\Modules\Support\Domain\Events\SupportTicketResolvedEvent;
use App\Modules\Identity\Domain\Contracts\IdentityModuleContract;
use App\Modules\Support\Domain\Exceptions\InvalidAssigneeException;
use App\Modules\Support\Domain\Exceptions\InvalidTicketStatusTransitionException;
use App\Modules\Support\Domain\Exceptions\TicketAlreadyClosedException;
use App\Modules\Support\Domain\Exceptions\TicketForbiddenException;
use App\Modules\Support\Domain\Exceptions\TicketNotFoundException;
use App\Modules\Support\Infrastructure\Models\DisputeModel;
use App\Modules\Support\Infrastructure\Models\SupportTicketModel;
use App\Modules\Support\Infrastructure\Models\TicketNoteModel;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\Contracts\IdempotencyServiceInterface;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class SupportTicketService
{
    public function __construct(
        private readonly IdempotencyServiceInterface $idempotency,
        private readonly EventPublisherInterface     $events,
        private readonly IdentityModuleContract      $identity,
    ) {}

    public function createTicket(
        array  $data,
        string $reporterId,
        string $reporterType,
        string $idempotencyKey,
        string $correlationId,
    ): SupportTicketModel {
        $existing = $this->idempotency->findCompletedResponse($idempotencyKey, $reporterId);
        if ($existing !== null) {
            $ticket = SupportTicketModel::where('idempotency_key', $idempotencyKey)->first();
            if ($ticket !== null) {
                return $ticket;
            }
        }

        $lock = $this->idempotency->acquireLock(
            idempotencyKey: $idempotencyKey,
            actorId:        $reporterId,
            requestHash:    md5(json_encode($data) . $reporterId . $reporterType),
            httpMethod:     'POST',
            httpPath:       '/v1/support/tickets',
            correlationId:  $correlationId,
        );

        try {
            $ticket = DB::connection('platform')->transaction(function () use (
                $data, $reporterId, $reporterType, $idempotencyKey, $correlationId
            ): SupportTicketModel {
                $seq = DB::connection('platform')
                    ->selectOne("SELECT nextval('support.ticket_number_seq') AS seq");
                $ticketNumber = sprintf('TKT-%06d', $seq->seq);

                $ticket = new SupportTicketModel();
                $ticket->id              = (string) Uuid::uuid7();
                $ticket->ticket_number   = $ticketNumber;
                $ticket->subject         = $data['subject'];
                $ticket->description     = $data['description'];
                $ticket->category        = TicketCategory::from($data['category']);
                $ticket->priority        = isset($data['priority'])
                    ? TicketPriority::from($data['priority'])
                    : TicketPriority::Medium;
                $ticket->status          = TicketStatus::Open;
                $ticket->reporter_id     = $reporterId;
                $ticket->reporter_type   = $reporterType;
                $ticket->idempotency_key = $idempotencyKey;
                $ticket->correlation_id  = $correlationId;
                $ticket->save();

                $this->events->publish(new SupportTicketCreatedEvent(
                    ticketId:      $ticket->id,
                    ticketNumber:  $ticketNumber,
                    reporterId:    $reporterId,
                    reporterType:  $reporterType,
                    category:      $data['category'],
                    occurredAt:    now()->toIso8601String(),
                    correlationId: $correlationId,
                ));

                return $ticket;
            });
        } catch (\Throwable $e) {
            $this->idempotency->recordFailure($lock, $e);
            throw $e;
        }

        $this->idempotency->recordResponse($lock, 201, ['ticket_id' => $ticket->id]);

        return $ticket;
    }

    public function listTickets(
        string  $actorId,
        string  $actorRole,
        array   $filters,
        ?string $cursor,
        int     $perPage = 20,
    ): array {
        $query = SupportTicketModel::query();

        if ($actorRole !== 'admin') {
            $query->forReporter($actorId);
        }

        if (isset($filters['status'])) {
            $query->forStatus(TicketStatus::from($filters['status']));
        }
        if (isset($filters['category'])) {
            $query->where('category', $filters['category']);
        }
        if (isset($filters['assignee_id'])) {
            $query->where('assignee_id', $filters['assignee_id']);
        }
        if (isset($filters['reporter_id']) && $actorRole === 'admin') {
            $query->where('reporter_id', $filters['reporter_id']);
        }
        if (isset($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }
        if (isset($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }

        if ($cursor !== null) {
            $decoded = json_decode(base64_decode($cursor), true);
            if (is_array($decoded) && isset($decoded['created_at'], $decoded['id'])) {
                $query->where(static function ($q) use ($decoded): void {
                    $q->where('created_at', '<', $decoded['created_at'])
                        ->orWhere(static function ($q2) use ($decoded): void {
                            $q2->where('created_at', '=', $decoded['created_at'])
                                ->where('id', '<', $decoded['id']);
                        });
                });
            }
        }

        $query->orderBy('created_at', 'desc')->orderBy('id', 'desc');
        $items = $query->limit($perPage + 1)->get();

        $hasMore    = $items->count() > $perPage;
        $items      = $hasMore ? $items->slice(0, $perPage) : $items;
        $nextCursor = null;

        if ($hasMore && $items->isNotEmpty()) {
            $last = $items->last();
            $nextCursor = base64_encode(json_encode([
                'created_at' => $last->created_at?->toIso8601String(),
                'id'         => $last->id,
            ]));
        }

        return [
            'items' => $items->values()->all(),
            'meta'  => ['next_cursor' => $nextCursor, 'prev_cursor' => null],
        ];
    }

    public function getTicket(string $ticketId, string $actorId, string $actorRole): SupportTicketModel
    {
        $ticket = SupportTicketModel::find($ticketId);

        if ($ticket === null) {
            throw new TicketNotFoundException("Support ticket [{$ticketId}] not found.");
        }

        if ($actorRole !== 'admin' && $ticket->reporter_id !== $actorId) {
            throw new \App\Modules\Support\Domain\Exceptions\TicketForbiddenException(
                "Access denied to ticket [{$ticketId}]."
            );
        }

        if ($actorRole !== 'admin') {
            $ticket->setRelation('notes', $ticket->notes()->publicOnly()->get());
        } else {
            $ticket->load('notes');
        }

        return $ticket;
    }

    public function assignTicket(
        string $ticketId,
        string $assigneeId,
        string $assignedById,
        string $correlationId,
    ): SupportTicketModel {
        $ticket = SupportTicketModel::find($ticketId);

        if ($ticket === null) {
            throw new TicketNotFoundException("Support ticket [{$ticketId}] not found.");
        }

        if ($ticket->status === TicketStatus::Closed) {
            throw new TicketAlreadyClosedException("Ticket [{$ticketId}] is closed.");
        }

        if (!$this->identity->hasPermission($assigneeId, 'support.assign')) {
            throw new InvalidAssigneeException(
                "User [{$assigneeId}] is not a valid support agent with 'support.assign' permission."
            );
        }

        $wasAssigned      = $ticket->assignee_id !== null;
        $previousAssignee = $ticket->assignee_id;

        if ($wasAssigned && $previousAssignee === $assigneeId) {
            return $ticket;
        }

        return DB::connection('platform')->transaction(function () use (
            $ticket, $assigneeId, $assignedById, $correlationId, $wasAssigned
        ): SupportTicketModel {
            $ticket->assignee_id = $assigneeId;

            if ($ticket->status === TicketStatus::Open) {
                $ticket->status = TicketStatus::InProgress;
            }

            $ticket->save();

            $now = now()->toIso8601String();

            if ($wasAssigned) {
                $this->events->publish(new SupportTicketReassignedEvent(
                    ticketId:     $ticket->id,
                    assigneeId:   $assigneeId,
                    assignedById: $assignedById,
                    occurredAt:   $now,
                    correlationId: $correlationId,
                ));
            } else {
                $this->events->publish(new SupportTicketAssignedEvent(
                    ticketId:     $ticket->id,
                    assigneeId:   $assigneeId,
                    assignedById: $assignedById,
                    occurredAt:   $now,
                    correlationId: $correlationId,
                ));
            }

            return $ticket;
        });
    }

    public function updateStatus(
        string       $ticketId,
        TicketStatus $newStatus,
        string       $actorId,
        string       $correlationId,
        ?string      $resolutionNote = null,
    ): SupportTicketModel {
        $ticket = SupportTicketModel::find($ticketId);

        if ($ticket === null) {
            throw new TicketNotFoundException("Support ticket [{$ticketId}] not found.");
        }

        if (!$ticket->status->isTransitionableTo($newStatus)) {
            throw new InvalidTicketStatusTransitionException(
                "Cannot transition ticket [{$ticketId}] from [{$ticket->status->value}] to [{$newStatus->value}]."
            );
        }

        $requiresNote = in_array($newStatus, [TicketStatus::Resolved, TicketStatus::Closed], true);
        if ($requiresNote && ($resolutionNote === null || trim($resolutionNote) === '')) {
            throw new InvalidTicketStatusTransitionException(
                'RESOLUTION_NOTE_REQUIRED: a resolution note is required when resolving or closing a ticket.'
            );
        }

        return DB::connection('platform')->transaction(function () use (
            $ticket, $newStatus, $actorId, $resolutionNote, $correlationId
        ): SupportTicketModel {
            $ticket->status = $newStatus;

            if ($newStatus === TicketStatus::Resolved) {
                $ticket->resolution_note = $resolutionNote;
                $ticket->resolved_at     = now();
                $ticket->closed_at       = null;
            } elseif ($newStatus === TicketStatus::Closed) {
                $ticket->resolution_note = $resolutionNote;
                $ticket->closed_at       = now();
            } elseif ($newStatus === TicketStatus::Open) {
                $ticket->resolved_at = null;
            }

            $ticket->save();

            $now = now()->toIso8601String();

            if ($newStatus === TicketStatus::Resolved) {
                $this->events->publish(new SupportTicketResolvedEvent(
                    ticketId:      $ticket->id,
                    actorId:       $actorId,
                    occurredAt:    $now,
                    correlationId: $correlationId,
                ));
            } elseif ($newStatus === TicketStatus::Open) {
                $this->events->publish(new SupportTicketReopenedEvent(
                    ticketId:      $ticket->id,
                    actorId:       $actorId,
                    occurredAt:    $now,
                    correlationId: $correlationId,
                ));
            }

            return $ticket;
        });
    }
}
