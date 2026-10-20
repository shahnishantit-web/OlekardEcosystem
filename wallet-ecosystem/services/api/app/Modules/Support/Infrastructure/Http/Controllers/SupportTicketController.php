<?php

declare(strict_types=1);

namespace App\Modules\Support\Infrastructure\Http\Controllers;

use App\Modules\Support\Application\Services\SupportTicketService;
use App\Modules\Support\Infrastructure\Http\Requests\CreateSupportTicketRequest;
use App\Modules\Support\Infrastructure\Http\Resources\SupportTicketResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class SupportTicketController extends Controller
{
    public function __construct(private readonly SupportTicketService $service) {}

    public function store(CreateSupportTicketRequest $request): JsonResponse
    {
        $reporterId    = (string) $request->attributes->get('identity.user_id');
        $reporterType  = (string) $request->attributes->get('identity.actor_type', 'customer');
        $idempotencyKey = (string) $request->header('Idempotency-Key', (string) Str::uuid());
        $correlationId  = (string) $request->header('X-Correlation-ID', (string) Str::uuid());

        $ticket = $this->service->createTicket(
            data:           $request->validated(),
            reporterId:     $reporterId,
            reporterType:   $reporterType,
            idempotencyKey: $idempotencyKey,
            correlationId:  $correlationId,
        );

        return (new SupportTicketResource($ticket))
            ->response()
            ->setStatusCode(201);
    }

    public function index(Request $request): JsonResponse
    {
        $actorId   = (string) $request->attributes->get('identity.user_id');
        $actorType = (string) $request->attributes->get('identity.actor_type', 'customer');
        $actorRole = $actorType === 'admin' ? 'admin' : 'user';

        $result = $this->service->listTickets(
            actorId:  $actorId,
            actorRole: $actorRole,
            filters:  $request->only(['status', 'category', 'assignee_id', 'reporter_id', 'date_from', 'date_to']),
            cursor:   $request->query('cursor'),
            perPage:  (int) $request->query('per_page', 20),
        );

        return response()->json([
            'data' => [
                'items' => SupportTicketResource::collection($result['items'])->resolve(),
                'meta'  => $result['meta'],
            ],
            'success' => true,
        ]);
    }

    public function show(Request $request, string $ticketId): JsonResponse
    {
        $actorId   = (string) $request->attributes->get('identity.user_id');
        $actorType = (string) $request->attributes->get('identity.actor_type', 'customer');
        $actorRole = $actorType === 'admin' ? 'admin' : 'user';

        $ticket = $this->service->getTicket($ticketId, $actorId, $actorRole);

        return response()->json([
            'data'    => new SupportTicketResource($ticket),
            'success' => true,
        ]);
    }
}
