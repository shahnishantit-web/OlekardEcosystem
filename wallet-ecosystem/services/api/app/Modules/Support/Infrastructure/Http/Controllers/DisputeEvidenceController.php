<?php

declare(strict_types=1);

namespace App\Modules\Support\Infrastructure\Http\Controllers;

use App\Modules\Support\Application\Services\DisputeEvidenceService;
use App\Modules\Support\Infrastructure\Http\Requests\AttachEvidenceRequest;
use App\Modules\Support\Infrastructure\Http\Resources\DisputeEvidenceResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class DisputeEvidenceController extends Controller
{
    public function __construct(private readonly DisputeEvidenceService $service) {}

    public function store(AttachEvidenceRequest $request, string $id): JsonResponse
    {
        $uploadedById   = (string) $request->attributes->get('identity.user_id');
        $actorType      = (string) $request->attributes->get('identity.actor_type', 'customer');
        $actorRole      = $actorType === 'admin' ? 'admin' : 'user';
        $idempotencyKey = (string) $request->header('Idempotency-Key', (string) Str::uuid());
        $correlationId  = (string) $request->header('X-Correlation-ID', (string) Str::uuid());

        $evidence = $this->service->attachEvidence(
            disputeId:      $id,
            uploadedById:   $uploadedById,
            actorRole:      $actorRole,
            file:           $request->file('file'),
            label:          $request->validated('label'),
            idempotencyKey: $idempotencyKey,
            correlationId:  $correlationId,
        );

        $statusCode = $evidence->wasRecentlyCreated ? 201 : 200;

        return (new DisputeEvidenceResource($evidence))
            ->response()
            ->setStatusCode($statusCode);
    }

    public function signedUrl(Request $request, string $id, string $evidenceId): JsonResponse
    {
        $actorId   = (string) $request->attributes->get('identity.user_id');
        $actorType = (string) $request->attributes->get('identity.actor_type', 'customer');
        $actorRole = $actorType === 'admin' ? 'admin' : 'user';

        $url = $this->service->getSignedUrl($id, $evidenceId, $actorId, $actorRole);

        return response()->json([
            'data'    => ['signed_url' => $url],
            'success' => true,
        ]);
    }
}
