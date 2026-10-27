<?php

declare(strict_types=1);

namespace App\Modules\Payment\Http\Controllers\Admin;

use App\Modules\Payment\Application\Services\CredentialRotationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * T060: Thin controller for credential rotation.
 * Route: POST /v1/admin/payments/banks/{bankId}/accounts/{accountId}/rotate-credential
 * Requires payment.configuration permission (route middleware).
 *
 * FR-014: Never returns raw credential values in any response.
 */
final class CredentialRotationAdminController extends Controller
{
    public function __construct(
        private readonly CredentialRotationService $rotationService,
    ) {
    }

    public function rotate(Request $request, string $bankId, string $accountId): JsonResponse
    {
        $validated = $request->validate([
            'secret_role' => 'required|string|max:128',
            'new_key_name' => 'required|string|max:500',
            'reason'       => 'nullable|string|max:1000',
        ]);

        $actorId = (string) $request->user()?->id;

        try {
            $logEntry = $this->rotationService->rotate(
                accountId:   $accountId,
                secretRole:  $validated['secret_role'],
                newKeyName:  $validated['new_key_name'],
                actorId:     $actorId,
                reason:      $validated['reason'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => ['code' => 'validation_failed', 'message' => $e->getMessage()],
            ], 422);
        }

        // FR-014: Return only log metadata — never key names or raw values
        return response()->json([
            'data' => [
                'rotation_log_id' => $logEntry->id,
                'secret_role'     => $logEntry->secret_role,
                'effective_from'  => $logEntry->effective_from,
                'rotated_at'      => $logEntry->created_at,
            ],
        ]);
    }
}
