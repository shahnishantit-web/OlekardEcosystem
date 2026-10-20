<?php

declare(strict_types=1);

namespace App\Modules\Service\Infrastructure\Http\Controllers;

use App\Modules\Service\Application\Services\AdminResolutionService;
use App\Modules\Service\Infrastructure\Http\Requests\AdminForceOutcomeRequest;
use App\Modules\Service\Infrastructure\Http\Resources\ServiceTransactionResource;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class AdminServiceResolutionController extends Controller
{
    public function __construct(private readonly AdminResolutionService $resolution) {}

    public function resolve(AdminForceOutcomeRequest $request, string $txId): ServiceTransactionResource
    {
        $validated = $request->validated();
        $actorId   = (string) $request->attributes->get('identity.user_id');

        $tx = $this->resolution->resolve(
            txId:          $txId,
            outcomeChoice: $validated['outcome'],
            rationale:     $validated['rationale'],
            actorId:       $actorId,
            correlationId: $request->header('X-Correlation-Id', (string) Str::uuid()),
            providerRef:   $validated['provider_reference'] ?? null,
        );

        return new ServiceTransactionResource($tx);
    }
}
