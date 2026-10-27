<?php

declare(strict_types=1);

namespace App\Modules\Payment\Http\Controllers\Admin;

use App\Modules\Payment\Application\Services\EligibilityPipeline;
use App\Modules\Payment\Application\Services\PaymentProviderAdminService;
use App\Modules\Payment\Domain\Contracts\AdapterAllowlistInterface;
use App\Modules\Payment\Domain\Contracts\PaymentProviderRepositoryInterface;
use App\Modules\Payment\Domain\Exceptions\AdapterNotInAllowlistException;
use App\Modules\Payment\Domain\Exceptions\StepUpRequiredException;
use App\Modules\Payment\Domain\ValueObjects\OptionsQuery;
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
        private readonly PaymentProviderAdminService       $service,
        private readonly AdapterAllowlistInterface         $adapterAllowlist,
        private readonly PaymentProviderRepositoryInterface $providerRepository,
        private readonly EligibilityPipeline               $eligibilityPipeline,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
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
        $validated = $request->validate([
            'code'                 => ['required', 'string', 'max:64'],
            'display_name'         => ['required', 'string', 'max:128'],
            'adapter_identifier'   => ['required', 'string', 'max:128'],
            'adapter_version'      => ['nullable', 'string', 'max:32'],
            'environment'          => ['required', 'in:sandbox,production,mock'],
            'is_default'           => ['nullable', 'boolean'],
            'sort_order'           => ['nullable', 'integer'],
        ]);

        $actorId       = (string) ($request->user()?->id ?? 'system');
        $correlationId = (string) Uuid::uuid7();

        try {
            $provider = $this->service->createProvider($validated, $actorId, $correlationId);
        } catch (AdapterNotInAllowlistException $e) {
            return response()->json([
                'message'    => 'Adapter not in allowlist.',
                'error_code' => 'ADAPTER_NOT_IN_ALLOWLIST',
                'adapter'    => $e->adapterIdentifier,
            ], 422);
        }

        return response()->json([
            'data' => $this->service->safeProviderShape($provider),
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'display_name'  => ['nullable', 'string', 'max:128'],
            'is_enabled'    => ['nullable', 'boolean'],
            'is_default'    => ['nullable', 'boolean'],
            'sort_order'    => ['nullable', 'integer'],
        ]);

        $actorId       = (string) ($request->user()?->id ?? 'system');
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
        $actorId       = (string) ($request->user()?->id ?? 'system');
        $correlationId = (string) Uuid::uuid7();

        $provider = $this->service->suspendProvider($id, $actorId, $correlationId);

        return response()->json([
            'data' => $this->service->safeProviderShape($provider),
        ]);
    }

    public function rotateCredential(Request $request, string $providerId, string $accountId): JsonResponse
    {
        $validated = $request->validate([
            'new_secret_reference' => ['required', 'string', 'min:8', 'max:512'],
            'step_up_token'        => ['required', 'string'],
        ]);

        $actorId       = (string) ($request->user()?->id ?? 'system');
        $sessionId     = (string) ($request->user()?->getAttribute('current_session_id') ?? '');
        $correlationId = (string) Uuid::uuid7();

        try {
            $this->service->rotateCredentialWithStepUp(
                $providerId,
                $accountId,
                $validated['new_secret_reference'],
                $actorId,
                $sessionId,
                $validated['step_up_token'],
                $correlationId,
            );
        } catch (StepUpRequiredException) {
            return response()->json([
                'error' => [
                    'code'        => 'STEP_UP_REQUIRED',
                    'message'     => 'Step-up authentication is required for credential rotation.',
                    'next_action' => ['type' => 'wallet_step_up'],
                ],
            ], 403);
        }

        return response()->json(['message' => 'Credential reference rotated successfully.']);
    }

    /**
     * GET /v1/payment/providers/adapters
     * Returns the list of adapter identifiers permitted for provider registration.
     */
    public function listAdapters(): JsonResponse
    {
        return response()->json([
            'data' => $this->adapterAllowlist->getAllowedIdentifiers(),
        ]);
    }

    /**
     * GET /v1/payment/providers/eligibility-preview
     * Returns ALL provider accounts annotated with eligibility status for the supplied context.
     */
    public function eligibilityPreview(Request $request): JsonResponse
    {
        $purposeType    = (string) ($request->query('purpose_type')      ?? '');
        $purposeRef     = (string) ($request->query('purpose_reference') ?? '');
        $currencyCode   = (string) ($request->query('currency')          ?? 'BWP');
        $amountMinor    = (int)    ($request->query('amount')            ?? 0);
        $organisationId = (string) ($request->query('organisation_id')   ?? '');
        $countryCode    = (string) ($request->query('country_code')      ?? '');
        $moduleKey      = (string) ($request->query('module_key')        ?? '');
        $requiredCap    = (string) ($request->query('required_capability') ?? '');

        $query = new OptionsQuery(
            purposeType:        $purposeType,
            purposeReference:   $purposeRef,
            currencyCode:       $currencyCode,
            amountMinor:        $amountMinor,
            organisationId:     $organisationId,
            actorId:            (string) ($request->user()?->id ?? ''),
            countryCode:        $countryCode,
            moduleKey:          $moduleKey,
            requiredCapability: $requiredCap !== '' ? $requiredCap : null,
        );

        $accounts = $this->providerRepository->findAllWithProviders();

        $data = [];
        foreach ($accounts as $account) {
            $provider    = $account->provider;
            $result      = $this->eligibilityPipeline->evaluate($account, $query);
            $data[] = [
                'provider_code'       => $provider?->code,
                'account_id'          => $account->id,
                'display_name'        => $provider?->display_name,
                'config_version'      => $account->config_version,
                'is_eligible'         => $result->isEligible,
                'ineligibility_reason' => $result->reason?->value,
            ];
        }

        return response()->json(['data' => $data]);
    }
}
