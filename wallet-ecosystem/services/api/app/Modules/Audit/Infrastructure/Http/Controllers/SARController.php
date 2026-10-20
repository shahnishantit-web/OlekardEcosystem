<?php

declare(strict_types=1);

namespace App\Modules\Audit\Infrastructure\Http\Controllers;

use App\Modules\Audit\Application\Services\SuspiciousActivityReportService;
use App\Modules\Audit\Infrastructure\Http\Requests\GenerateSARRequest;
use App\Modules\Audit\Infrastructure\Http\Resources\SARResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

final class SARController extends Controller
{
    public function __construct(
        private readonly SuspiciousActivityReportService $service,
    ) {}

    public function store(GenerateSARRequest $request): JsonResponse
    {

        $idempotencyKey = $request->header('Idempotency-Key')
            ?? abort(422, 'Idempotency-Key header is required');

        $actor = $request->user() ?? abort(401);

        $sar = $this->service->generateSAR(
            subjectUserId:        $request->input('subject_user_id'),
            reasonCode:           $request->input('reason_code'),
            reasonNarrative:      $request->input('reason_narrative'),
            flaggedTransactionIds: $request->input('flagged_transaction_ids', []),
            idempotencyKey:        $idempotencyKey,
            actorId:               (string) $actor->id,
            correlationId:         $idempotencyKey,
        );

        $isNew = $sar->wasRecentlyCreated;

        return response()->json(
            ['data' => new SARResource($sar)],
            $isNew ? 201 : 200
        );
    }

    public function show(string $id): JsonResponse
    {

        return response()->json([
            'data' => new SARResource($this->service->getSAR($id)),
        ]);
    }
}
