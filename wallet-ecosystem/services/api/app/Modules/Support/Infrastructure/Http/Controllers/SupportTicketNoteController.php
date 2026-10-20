<?php

declare(strict_types=1);

namespace App\Modules\Support\Infrastructure\Http\Controllers;

use App\Modules\Support\Application\Services\SupportTicketNoteService;
use App\Modules\Support\Infrastructure\Http\Requests\AddTicketNoteRequest;
use App\Modules\Support\Infrastructure\Http\Resources\TicketNoteResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class SupportTicketNoteController extends Controller
{
    public function __construct(private readonly SupportTicketNoteService $service) {}

    public function __invoke(AddTicketNoteRequest $request, string $id): JsonResponse
    {
        $authorId      = (string) $request->attributes->get('identity.user_id');
        $authorRole    = (string) $request->attributes->get('identity.actor_type', 'customer');
        $correlationId = (string) $request->header('X-Correlation-ID', (string) Str::uuid());

        $note = $this->service->addNote(
            ticketId:      $id,
            authorId:      $authorId,
            authorRole:    $authorRole,
            content:       $request->validated('content'),
            isInternal:    (bool) $request->validated('is_internal', false),
            correlationId: $correlationId,
        );

        return (new TicketNoteResource($note))
            ->response()
            ->setStatusCode(201);
    }
}
