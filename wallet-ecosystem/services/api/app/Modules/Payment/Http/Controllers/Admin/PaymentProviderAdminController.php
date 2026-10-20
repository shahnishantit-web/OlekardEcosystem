<?php

declare(strict_types=1);

namespace App\Modules\Payment\Http\Controllers\Admin;

use App\Modules\Payment\Application\Services\PaymentProviderAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Ramsey\Uuid\Uuid;

/**
 * T064: Admin controller for payment provider management.
 *
 * Controller is intentionally thin — all business logic lives in
 * PaymentProviderAdminService. Credentials are NEVER returned in any response.
 */
final class PaymentProviderAdminController extends Controller
{
    public function __construct(
        private readonly PaymentProviderAdminService $service,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('payment.providers.read');

        $providers = $this->service->listProviders();

        return response()->json([
            'data' => array_map(
                fn($p) => $this->service->safeProviderShape($p),
                $providers
            ),
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $this->authorize('payment.providers.read');

        $provider = $this->service->findProvider($id);

        if ($provider === null) {
            return response()->json(['error' => 'NOT_FOUND', 'message' => 'Provider not found.'], 404);
        }

        return response()->json([
            'data' => $this->service->safeProviderShape($provider),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('payment.providers.manage');

        $validated = $request->validate([
            'code'                 => ['required', 'string', 'max:64'],
            'display_name'         => ['required', 'string', 'max:128'],
            'adapter_identifier'   => ['required', 'string', 'max:128'],
            'adapter_version'      => ['nullable', 'string', 'max:32'],
            'environment'          => ['required', 'in:sandbox,production,mock'],
            'is_default'           => ['nullable', 'boolean'],
            'sort_order'           => ['nullable', 'integer'],
        ]);

        $actorId       = $request->user()?->id ?? 'system';
        $correlationId = (string) Uuid::uuid7();

        $provider = $this->service->createProvider($validated, $actorId, $correlationId);

        return response()->json([
            'data' => $this->service->safeProviderShape($provider),
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->authorize('payment.providers.manage');

        $validated = $request->validate([
            'display_name'  => ['nullable', 'string', 'max:128'],
            'is_enabled'    => ['nullable', 'boolean'],
            'is_default'    => ['nullable', 'boolean'],
            'sort_order'    => ['nullable', 'integer'],
        ]);

        $actorId       = $request->user()?->id ?? 'system';
        $correlationId = (string) Uuid::uuid7();

        $result = $this->service->updateProvider($id, $validated, $actorId, $correlationId);

        if ($result['requires_approval']) {
            return response()->json([
                'message'           => 'High-risk fields require change approval.',
                'requires_approval' => true,
                'high_risk_fields'  => $result['high_risk_fields'],
            ], 422);
        }

        return response()->json([
            'data' => $this->service->safeProviderShape($result['provider']),
        ]);
    }

    public function suspend(Request $request, string $id): JsonResponse
    {
        $this->authorize('payment.providers.manage');

        $actorId       = $request->user()?->id ?? 'system';
        $correlationId = (string) Uuid::uuid7();

        $provider = $this->service->suspendProvider($id, $actorId, $correlationId);

        return response()->json([
            'data' => $this->service->safeProviderShape($provider),
        ]);
    }

    public function rotateCredential(Request $request, string $providerId, string $accountId): JsonResponse
    {
        $this->authorize('payment.config.approve_high_risk');

        $validated = $request->validate([
            'new_secret_reference' => ['required', 'string', 'min:8', 'max:512'],
        ]);

        $actorId       = $request->user()?->id ?? 'system';
        $correlationId = (string) Uuid::uuid7();

        $this->service->rotateCredentialReference(
            $providerId,
            $accountId,
            $validated['new_secret_reference'],
            $actorId,
            $correlationId,
        );

        return response()->json(['message' => 'Credential reference rotated successfully.']);
    }
}
