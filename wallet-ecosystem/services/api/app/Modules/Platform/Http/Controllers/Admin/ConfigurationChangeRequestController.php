<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers\Admin;

use App\Http\Controllers\ApiController;
use App\Modules\Platform\Application\Services\ConfigurationMakerCheckerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ConfigurationChangeRequestController extends ApiController
{
    public function __construct(
        private readonly ConfigurationMakerCheckerService $makerCheckerService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $result = $this->makerCheckerService->listChangeRequests(
            filters: $request->only(['status', 'domain', 'config_key']),
            perPage: (int) $request->input('per_page', 20),
            actorId: $request->attributes->get('identity.user_id'),
            correlationId: $request->header('X-Correlation-ID', ''),
        );

        return $this->ok($result);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'config_key'      => ['required', 'string', 'max:255'],
            'proposed_value'  => ['required'],
            'idempotency_key' => ['required', 'string', 'max:255'],
        ]);

        $result = $this->makerCheckerService->submit(
            configKey:      $validated['config_key'],
            proposedValue:  $validated['proposed_value'],
            makerId:        (string) $request->attributes->get('identity.user_id'),
            idempotencyKey: $validated['idempotency_key'],
            correlationId:  $request->header('X-Correlation-ID', ''),
        );

        return $this->created($result);
    }

    public function show(string $id, Request $request): JsonResponse
    {
        $result = $this->makerCheckerService->getChangeRequest(
            id:           $id,
            actorId:      $request->attributes->get('identity.user_id'),
            correlationId: $request->header('X-Correlation-ID', ''),
        );

        if ($result === null) {
            return $this->notFound("Change request '{$id}' not found.");
        }

        return $this->ok($result);
    }

    public function approve(string $id, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'comments'        => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['required', 'string', 'max:255'],
        ]);

        $result = $this->makerCheckerService->approve(
            changeRequestId: $id,
            checkerId:       (string) $request->attributes->get('identity.user_id'),
            idempotencyKey:  $validated['idempotency_key'],
            comments:        $validated['comments'] ?? null,
            correlationId:   $request->header('X-Correlation-ID', ''),
        );

        return $this->ok($result);
    }

    public function reject(string $id, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'reason'          => ['required', 'string', 'max:1000'],
            'idempotency_key' => ['required', 'string', 'max:255'],
        ]);

        $result = $this->makerCheckerService->reject(
            changeRequestId: $id,
            checkerId:       (string) $request->attributes->get('identity.user_id'),
            idempotencyKey:  $validated['idempotency_key'],
            reason:          $validated['reason'],
            correlationId:   $request->header('X-Correlation-ID', ''),
        );

        return $this->ok($result);
    }

    public function withdraw(string $id, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:255'],
        ]);

        $result = $this->makerCheckerService->withdraw(
            changeRequestId: $id,
            makerId:         (string) $request->attributes->get('identity.user_id'),
            idempotencyKey:  $validated['idempotency_key'],
            correlationId:   $request->header('X-Correlation-ID', ''),
        );

        return $this->ok($result);
    }
}
