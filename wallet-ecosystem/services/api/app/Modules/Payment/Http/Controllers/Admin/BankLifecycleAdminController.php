<?php

declare(strict_types=1);

namespace App\Modules\Payment\Http\Controllers\Admin;

use App\Modules\Payment\Domain\Contracts\BankAccountLifecycleServiceInterface;
use App\Modules\Payment\Domain\Exceptions\ApprovalConflictException;
use App\Modules\Payment\Domain\Exceptions\LifecycleTransitionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Thin lifecycle controller — single transition endpoint.
 * Requires payment.configuration permission (route middleware).
 */
final class BankLifecycleAdminController extends Controller
{
    private const ALLOWED_ACTIONS = [
        'submit_for_validation',
        'initiate_sandbox_testing',
        'submit_evidence',
        'approve',
        'reject',
        'request_changes',
        'activate',
        'suspend',
        'reactivate',
        'retire',
    ];

    public function __construct(
        private readonly BankAccountLifecycleServiceInterface $lifecycleService,
    ) {
    }

    public function transition(Request $request, string $bankId, string $accountId): JsonResponse
    {
        $validated = $request->validate([
            'action'   => ['required', 'string', 'in:'.implode(',', self::ALLOWED_ACTIONS)],
            'reason'   => 'nullable|string|max:1000',
            'resolution_route_recorded' => 'nullable|boolean',
        ]);

        $action  = $validated['action'];
        $reason  = $validated['reason'] ?? '';
        $actorId = (string) $request->user()?->id;

        try {
            $account = match ($action) {
                'submit_for_validation'    => $this->lifecycleService->submitForValidation($accountId, $actorId),
                'initiate_sandbox_testing' => $this->lifecycleService->initiateSandboxTesting($accountId, $actorId),
                'submit_evidence'          => $this->lifecycleService->submitEvidence($accountId, $actorId),
                'approve'                  => $this->lifecycleService->approve($accountId, $actorId),
                'reject'                   => $this->lifecycleService->reject($accountId, $reason, $actorId),
                'request_changes'          => $this->lifecycleService->requestChanges($accountId, $reason, $actorId),
                'activate'                 => $this->lifecycleService->activate($accountId, $actorId),
                'suspend'                  => $this->lifecycleService->suspend($accountId, $actorId),
                'reactivate'               => $this->lifecycleService->reactivate($accountId, $actorId),
                'retire'                   => $this->lifecycleService->retire($accountId, $actorId, (bool) ($validated['resolution_route_recorded'] ?? false)),
                default                    => throw new \InvalidArgumentException("Unknown action: {$action}"),
            };
        } catch (ApprovalConflictException $e) {
            return response()->json([
                'error' => ['code' => 'approval_conflict', 'message' => $e->getMessage()],
            ], 409);
        } catch (LifecycleTransitionException $e) {
            return response()->json([
                'error' => [
                    'code'        => 'invalid_lifecycle_transition',
                    'message'     => $e->getMessage(),
                    'from_status' => $e->fromStatus,
                    'to_status'   => $e->toStatus,
                    'reason'      => $e->reason,
                ],
            ], 409);
        }

        return response()->json([
            'data' => [
                'id'         => $account->id,
                'new_status' => $account->account_lifecycle_status,
            ],
        ]);
    }
}
