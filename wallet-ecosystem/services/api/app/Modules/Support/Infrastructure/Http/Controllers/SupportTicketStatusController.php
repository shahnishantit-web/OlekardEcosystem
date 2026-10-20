<?php

declare(strict_types=1);

namespace App\Modules\Support\Infrastructure\Http\Controllers;

use App\Modules\Support\Application\Services\SupportTicketService;
use App\Modules\Support\Domain\Enums\TicketStatus;
use App\Modules\Support\Infrastructure\Http\Requests\UpdateTicketStatusRequest;
use App\Modules\Support\Infrastructure\Http\Resources\SupportTicketResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class SupportTicketStatusController extends Controller
{
    public function __construct(private readonly SupportTicketService $service) {}

    public function __invoke(UpdateTicketStatusRequest $request, string $id): JsonResponse
    {
        $actorId       = (string) $request->attributes->get('identity.user_id');
        $correlationId = (string) $request->header('X-Correlation-ID', (string) Str::uuid());

        $ticket = $this->service->updateStatus(
            ticketId:       $id,
            newStatus:      TicketStatus::from($request->validated('status')),
            actorId:        $actorId,
            correlationId:  $correlationId,
            resolutionNote: $request->validated('resolution_note'),
        );

        return response()->json([
            'data'    => new SupportTicketResource($ticket),
            'success' => true,
        ]);
    }
}
