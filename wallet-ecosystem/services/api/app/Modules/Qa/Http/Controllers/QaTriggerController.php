<?php

declare(strict_types=1);

namespace App\Modules\Qa\Http\Controllers;

use App\Modules\Qa\Application\Services\QaRunService;
use App\Modules\Qa\Exceptions\QaCategoryAlreadyRunningException;
use App\Modules\Qa\Http\Requests\TriggerQaRunRequest;
use App\Modules\Qa\Http\Resources\TestRunResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

final class QaTriggerController extends Controller
{
    public function __construct(private readonly QaRunService $qaRunService) {}

    public function trigger(string $category, TriggerQaRunRequest $request): JsonResponse
    {
        $idempotencyKey = $request->getIdempotencyKey();
        $qaCategory     = $request->getQaCategory();
        $actorId        = (string) ($request->user()?->id ?? 'unknown');
        $correlationId  = $request->getCorrelationId();

        try {
            [$run, $isNew] = $this->qaRunService->triggerRun(
                $qaCategory,
                $idempotencyKey,
                $actorId,
                $correlationId,
            );
        } catch (QaCategoryAlreadyRunningException $e) {
            return response()->json([
                'error' => [
                    'code'    => 'QA_CATEGORY_RUNNING',
                    'message' => $e->getMessage(),
                ],
            ], 409);
        }

        return response()->json(
            ['data' => new TestRunResource($run)],
            $isNew ? 202 : 200,
        );
    }
}
