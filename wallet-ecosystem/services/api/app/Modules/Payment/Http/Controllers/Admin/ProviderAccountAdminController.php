<?php

declare(strict_types=1);

namespace App\Modules\Payment\Http\Controllers\Admin;

use App\Modules\Payment\Application\Services\PaymentConfigDraftService;
use App\Modules\Payment\Application\Services\PaymentProviderAdminService;
use App\Modules\Payment\Domain\Exceptions\ConfigurationVersionConflictException;
use App\Modules\Payment\Infrastructure\Models\PaymentProviderModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Ramsey\Uuid\Uuid;

/**
 * P20: Thin controller for provider account management.
 *
 * All business logic lives in PaymentProviderAdminService.
 * Credentials are NEVER returned in any response.
 * Permission enforcement via route middleware only — no $this->authorize().
 */
final class ProviderAccountAdminController extends Controller
{
    public function __construct(
        private readonly PaymentProviderAdminService $adminService,
        private readonly PaymentConfigDraftService   $draftService,
    ) {
    }

    public function index(Request $request, string $providerId): JsonResponse
    {
        $accounts = $this->adminService->listAccounts($providerId);

        return response()->json([
            'data' => array_map(
                fn($account) => $this->adminService->safeAccountShape($account),
                $accounts
            ),
        ]);
    }

    public function show(Request $request, string $providerId, string $accountId): JsonResponse
    {
        $account = $this->adminService->getAccount($providerId, $accountId);

        return response()->json([
            'data' => $this->adminService->safeAccountShape($account),
        ]);
    }

    public function update(Request $request, string $providerId, string $accountId): JsonResponse
    {
        $validated = $request->validate([
            'config_version'             => ['required', 'integer'],
            'supported_currencies'       => ['nullable', 'array'],
            'supported_currencies.*'     => ['string', 'max:3'],
            'supported_payment_methods'  => ['nullable', 'array'],
            'supported_payment_methods.*' => ['string'],
            'min_amount_minor'           => ['nullable', 'integer', 'min:0'],
            'max_amount_minor'           => ['nullable', 'integer', 'min:0'],
            'enabled_organisations'      => ['nullable', 'array'],
            'enabled_modules'            => ['nullable', 'array'],
            'enabled_purpose_types'      => ['nullable', 'array'],
            'country_restrictions'       => ['nullable', 'array'],
            'capability_flags'           => ['nullable', 'array'],
            'fee_rule_ids'               => ['nullable', 'array'],
            'daily_limit_minor'          => ['nullable', 'integer', 'min:0'],
            'display_label'              => ['nullable', 'string', 'max:128'],
            'display_description'        => ['nullable', 'string', 'max:512'],
            'display_icon_key'           => ['nullable', 'string', 'max:128'],
            'display_badge'              => ['nullable', 'string', 'max:64'],
            'display_sort_order'         => ['nullable', 'integer'],
            // High-risk fields — presence triggers draft workflow
            'merchant_account_reference' => ['nullable', 'string', 'max:255'],
            'callback_secret_reference'  => ['nullable', 'string', 'max:512'],
            'settlement_destination'     => ['nullable', 'string', 'max:512'],
        ]);

        $configVersion = (int) $validated['config_version'];
        unset($validated['config_version']);

        $actorId       = (string) ($request->user()?->id ?? 'system');
        $correlationId = (string) ($request->header('X-Correlation-ID') ?? Uuid::uuid7()->toString());

        try {
            $result = $this->adminService->updateAccount(
                $providerId,
                $accountId,
                $validated,
                $actorId,
                $correlationId,
                $configVersion,
            );
        } catch (ConfigurationVersionConflictException $e) {
            return response()->json([
                'error' => [
                    'code'    => 'CONFIGURATION_VERSION_CONFLICT',
                    'message' => $e->getMessage(),
                ],
            ], 409);
        }

        if ($result['type'] === 'requires_approval') {
            // High-risk change detected — submit a draft for maker-checker approval
            $provider       = PaymentProviderModel::on('payment')->findOrFail($providerId);
            $idempotencyKey = (string) ($request->header('Idempotency-Key') ?? Uuid::uuid7()->toString());

            /** @var string[] $highRiskFields */
            $highRiskFields = $result['high_risk_fields'];

            $draft = $this->draftService->submitDraft(
                data: [
                    'provider_code'    => $provider->code,
                    'change_type'      => 'high_risk_update',
                    'proposed_changes' => array_merge(
                        ['account_id' => $accountId],
                        array_intersect_key($validated, array_flip($highRiskFields))
                    ),
                ],
                actorId: $actorId,
                idempotencyKey: $idempotencyKey,
                correlationId: $correlationId,
            );

            return response()->json([
                'requires_approval' => true,
                'draft_id'          => $draft->id,
                'high_risk_fields'  => $highRiskFields,
                'message'           => 'High-risk changes require approval. Draft submitted.',
            ], 202);
        }

        return response()->json([
            'data' => $this->adminService->safeAccountShape($result['account']),
        ]);
    }

    public function lifecycle(Request $request, string $providerId, string $accountId): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'string', 'in:activate,suspend,restore,retire,reject'],
            'reason' => ['required', 'string', 'min:1', 'max:2000'],
        ]);

        $actorId       = (string) ($request->user()?->id ?? 'system');
        $correlationId = (string) ($request->header('X-Correlation-ID') ?? Uuid::uuid7()->toString());

        try {
            $account = $this->adminService->transitionLifecycle(
                $providerId,
                $accountId,
                $validated['action'],
                $validated['reason'],
                $actorId,
                $correlationId,
            );
        } catch (\RuntimeException $e) {
            return response()->json([
                'error' => [
                    'code'    => 'INVALID_LIFECYCLE_TRANSITION',
                    'message' => $e->getMessage(),
                ],
            ], 409);
        }

        return response()->json([
            'data' => $this->adminService->safeAccountShape($account),
        ]);
    }
}
