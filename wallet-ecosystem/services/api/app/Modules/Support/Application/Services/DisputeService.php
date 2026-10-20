<?php

declare(strict_types=1);

namespace App\Modules\Support\Application\Services;

use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\Contracts\IdempotencyServiceInterface;
use App\Modules\Support\Domain\Enums\DisputeStatus;
use App\Modules\Support\Domain\Enums\TicketStatus;
use App\Modules\Support\Domain\Events\DisputeRaisedEvent;
use App\Modules\Support\Domain\Exceptions\DisputeAlreadyExistsException;
use App\Modules\Support\Domain\Exceptions\DisputeForbiddenException;
use App\Modules\Support\Domain\Exceptions\DisputeInvalidTicketException;
use App\Modules\Support\Domain\Exceptions\DisputeNotFoundException;
use App\Modules\Support\Domain\Exceptions\TransactionNotDisputableException;
use App\Modules\Support\Infrastructure\Models\DisputeModel;
use App\Modules\Support\Infrastructure\Models\SupportTicketModel;
use App\Modules\Transaction\Domain\Contracts\TransactionModuleContract;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class DisputeService
{
    private const DISPUTABLE_STATUSES = ['completed', 'failed'];

    public function __construct(
        private readonly EventPublisherInterface     $events,
        private readonly IdempotencyServiceInterface $idempotency,
        private readonly TransactionModuleContract   $transactions,
    ) {}

    public function raiseDispute(
        string $ticketId,
        string $transactionId,
        string $raisedById,
        string $raisedByType,
        string $idempotencyKey,
        string $correlationId,
    ): DisputeModel {
        if ($existing = $this->idempotency->findCompletedResponse($idempotencyKey, $raisedById)) {
            $disputeId = $existing['body']['dispute_id'] ?? null;
            if ($disputeId !== null) {
                $replay = DisputeModel::find($disputeId);
                if ($replay !== null) {
                    return $replay;
                }
            }
        }

        $lock = $this->idempotency->acquireLock(
            $idempotencyKey,
            $raisedById,
            hash('sha256', "{$raisedById}:{$idempotencyKey}"),
            'POST',
            '/v1/disputes',
            $correlationId,
        );

        $ticket = SupportTicketModel::find($ticketId);
        if ($ticket === null) {
            throw new DisputeInvalidTicketException("Ticket [{$ticketId}] not found.");
        }

        if ($ticket->reporter_id !== $raisedById) {
            throw new DisputeForbiddenException("Access denied to ticket [{$ticketId}].");
        }

        if ($ticket->status === TicketStatus::Closed) {
            throw new DisputeInvalidTicketException("Ticket [{$ticketId}] is closed.");
        }

        $txStatus = $this->transactions->status($transactionId);
        if ($txStatus === null || !in_array($txStatus, self::DISPUTABLE_STATUSES, true)) {
            throw new TransactionNotDisputableException(
                "Transaction [{$transactionId}] is not in a terminal state (status: {$txStatus})."
            );
        }

        $active = DisputeModel::query()->activeForTransaction($transactionId)->exists();
        if ($active) {
            throw new DisputeAlreadyExistsException();
        }

        $dispute = DB::connection('platform')->transaction(function () use (
            $ticket, $ticketId, $transactionId, $raisedById, $raisedByType, $correlationId
        ): DisputeModel {
            $seq            = DB::connection('platform')->selectOne("SELECT nextval('support.dispute_number_seq') AS seq");
            $disputeNumber  = sprintf('DSP-%06d', $seq->seq);

            $dispute                 = new DisputeModel();
            $dispute->id             = (string) Uuid::uuid7();
            $dispute->dispute_number = $disputeNumber;
            $dispute->ticket_id      = $ticketId;
            $dispute->transaction_id = $transactionId;
            $dispute->reporter_id    = $raisedById;
            $dispute->reporter_type  = $raisedByType;
            $dispute->description    = $ticket->description;
            $dispute->status         = DisputeStatus::Raised;
            $dispute->escalated      = false;
            $dispute->refund_pending_confirmation = false;
            $dispute->correlation_id = $correlationId;
            $dispute->save();

            $this->events->publish(new DisputeRaisedEvent(
                disputeId:     $dispute->id,
                disputeNumber: $disputeNumber,
                ticketId:      $ticketId,
                transactionId: $transactionId,
                raisedById:    $raisedById,
                occurredAt:    now()->toIso8601String(),
                correlationId: $correlationId,
            ));

            return $dispute;
        });

        $this->idempotency->recordResponse($lock, 201, ['dispute_id' => $dispute->id]);

        return $dispute;
    }

    public function listDisputes(
        string  $actorId,
        string  $actorRole,
        array   $filters = [],
        ?string $cursor = null,
        int     $perPage = 20,
    ): array {
        $query = DisputeModel::query();

        if ($actorRole !== 'admin') {
            $query->where('reporter_id', $actorId);
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['escalated']) && $filters['escalated']) {
            $query->where('escalated', true);
        }

        if ($cursor !== null) {
            $decoded = json_decode(base64_decode($cursor), true);
            if ($decoded && isset($decoded['created_at'], $decoded['id'])) {
                $query->where(function ($q) use ($decoded): void {
                    $q->where('created_at', '<', $decoded['created_at'])
                      ->orWhere(function ($q2) use ($decoded): void {
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

    public function getDispute(string $disputeId, string $actorId, string $actorRole): DisputeModel
    {
        $dispute = DisputeModel::with('evidence')->find($disputeId);

        if ($dispute === null) {
            throw new DisputeNotFoundException("Dispute [{$disputeId}] not found.");
        }

        if ($actorRole !== 'admin' && $dispute->reporter_id !== $actorId) {
            throw new DisputeForbiddenException("Access denied to dispute [{$disputeId}].");
        }

        return $dispute;
    }
}
