<?php

declare(strict_types=1);

namespace App\Modules\Payment\Http\Controllers\Admin;

use App\Modules\Payment\Application\Services\PaymentConfigDraftService;
use App\Modules\Payment\Application\Services\PaymentProviderAdminService;
use App\Modules\Payment\Domain\Exceptions\ConfigurationVersionConflictException;
use App\Modules\Payment\Domain\Exceptions\InvalidStateTransitionException;
use App\Modules\Payment\Domain\Exceptions\SelfApprovalNotAllowedException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Ramsey\Uuid\Uuid;

/**
 * Thin controller for high-risk provider configuration change drafts.
 * All business logic lives in PaymentConfigDraftService.
 * Permission enforcement is via route middleware ONLY (no $this->authorize()).
 */
final class PaymentConfigChangeDraftAdminController extends Controller
{
    public function __construct(
        private readonly PaymentConfigDraftService     $draftService,
        private readonly PaymentProviderAdminService   $adminService,
    ) {
    }

    public function store(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'change_type'       => ['required', 'string', 'in:high_risk_update,credential_rotation'],
            'proposed_changes'  => ['required', 'array'],
            'notes'             => ['nullable', 'string', 'max:2000'],
        ]);

        // P05: Validate draft payload before storing
        $proposedChanges = $validated['proposed_changes'] ?? [];
        $validationErrors = $this->adminService->validateDraftPayload($proposedChanges);
        if (! empty($validationErrors)) {
            return response()->json([
                'message'    => 'Draft validation failed.',
                'error_code' => 'DRAFT_VALIDATION_FAILED',
                'errors'     => $validationErrors,
            ], 422);
        }

        $actorId        = (string) ($request->user()?->id ?? 'system');
        $idempotencyKey = (string) $request->header('Idempotency-Key', Uuid::uuid7()->toString());
        $correlationId  = (string) Uuid::uuid7();

        $draft = $this->draftService->submitDraft(
            data: array_merge($validated, ['provider_code' => $id]),
            actorId: $actorId,
            idempotencyKey: $idempotencyKey,
            correlationId: $correlationId,
        );

        return response()->json(['data' => $this->safeDraftShape($draft)], 201);
    }

    public function approve(Request $request, string $id, string $draftId): JsonResponse
    {
        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $actorId       = (string) ($request->user()?->id ?? 'system');
        $correlationId = (string) Uuid::uuid7();

        try {
            $draft = $this->draftService->approveDraft(
                draftId: $draftId,
                approverActorId: $actorId,
                notes: $validated['notes'] ?? null,
                correlationId: $correlationId,
            );
        } catch (SelfApprovalNotAllowedException) {
            return response()->json([
                'error' => ['code' => 'SELF_APPROVAL_NOT_ALLOWED', 'message' => 'The drafter cannot approve their own change.'],
            ], 403);
        } catch (ConfigurationVersionConflictException $e) {
            return response()->json([
                'error' => ['code' => 'CONFIGURATION_VERSION_CONFLICT', 'message' => $e->getMessage()],
            ], 409);
        } catch (InvalidStateTransitionException $e) {
            return response()->json([
                'error' => ['code' => 'DRAFT_NOT_PENDING', 'message' => $e->getMessage()],
            ], 409);
        }

        return response()->json(['data' => $this->safeDraftShape($draft)]);
    }

    public function reject(Request $request, string $id, string $draftId): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $actorId       = (string) ($request->user()?->id ?? 'system');
        $correlationId = (string) Uuid::uuid7();

        try {
            $draft = $this->draftService->rejectDraft(
                draftId: $draftId,
                reviewerActorId: $actorId,
                reason: $validated['reason'],
                correlationId: $correlationId,
            );
        } catch (SelfApprovalNotAllowedException) {
            return response()->json([
                'error' => ['code' => 'SELF_APPROVAL_NOT_ALLOWED', 'message' => 'The drafter cannot reject their own change.'],
            ], 403);
        } catch (InvalidStateTransitionException $e) {
            return response()->json([
                'error' => ['code' => 'DRAFT_NOT_PENDING', 'message' => $e->getMessage()],
            ], 409);
        }

        return response()->json(['data' => $this->safeDraftShape($draft)]);
    }

    /** @return array<string, mixed> — Never exposes change_payload fields */
    private function safeDraftShape(\App\Modules\Payment\Infrastructure\Models\PaymentConfigChangeDraftModel $draft): array
    {
        return [
            'id'                   => $draft->id,
            'provider_id'          => $draft->provider_id,
            'change_type'          => $draft->change_type,
            'status'               => $draft->status->value,
            'drafted_by_actor_id'  => $draft->drafted_by_actor_id,
            'reviewed_by_actor_id' => $draft->reviewed_by_actor_id,
            'reviewed_at'          => $draft->reviewed_at?->toIso8601String(),
            'created_at'           => $draft->created_at?->toIso8601String(),
            // change_payload intentionally omitted — may contain sensitive references
        ];
    }
}
