<?php

declare(strict_types=1);

namespace App\Modules\Reconciliation\Infrastructure\Http\Controllers;

use App\Modules\Reconciliation\Application\Services\ReconciliationExceptionService;
use App\Modules\Reconciliation\Infrastructure\Http\Requests\ResolveExceptionRequest;
use App\Modules\Reconciliation\Infrastructure\Http\Resources\ExceptionDetailResource;
use App\Modules\Reconciliation\Infrastructure\Http\Resources\ExceptionSummaryResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ReconciliationExceptionController
{
    public function __construct(private readonly ReconciliationExceptionService $exceptionService) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $exceptions = $this->exceptionService->listExceptions(
            status:     $request->input('status'),
            batchId:    $request->input('batch_id'),
            assignedTo: $request->input('assigned_to'),
            page:       (int) $request->input('page', 1),
            perPage:    (int) $request->input('per_page', 25),
        );

        return ExceptionSummaryResource::collection($exceptions);
    }

    public function show(string $exceptionId): ExceptionDetailResource
    {
        $exception = $this->exceptionService->getException($exceptionId);
        return new ExceptionDetailResource($exception);
    }

    public function resolve(ResolveExceptionRequest $request, string $exceptionId): ExceptionDetailResource
    {
        $exception = $this->exceptionService->resolveException(
            exceptionId:    $exceptionId,
            resolutionType: $request->input('resolution_type'),
            resolution:     $request->input('resolution'),
            resolvedBy:     (string) $request->user()->id,
        );

        return new ExceptionDetailResource($exception);
    }
}
