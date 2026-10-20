<?php

declare(strict_types=1);

namespace App\Modules\Profile\Infrastructure\Http\Controllers;

use App\Modules\Profile\Application\Queries\GetAccountStatusQuery;
use App\Modules\Profile\Domain\Exceptions\ProfileNotFoundException;
use App\Modules\Profile\Infrastructure\Http\Resources\AccountStatusResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class AccountStatusController extends Controller
{
    public function __construct(
        private readonly GetAccountStatusQuery $getStatus,
    ) {}

    /**
     * GET /v1/users/profile/status
     * Returns current account_status and transition history for the authenticated user.
     * IDOR: userId from JWT attributes — never from request body or path.
     * actor_id excluded from history entries (user path per spec §6 US8).
     */
    public function show(Request $request): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $userId        = $request->attributes->get('identity.user_id');

        try {
            $status = $this->getStatus->execute($userId, includeActorId: false);
            return response()->json(
                (new AccountStatusResource($status))->toArray($request),
                200,
                ['X-Correlation-ID' => $correlationId],
            );
        } catch (ProfileNotFoundException) {
            return $this->errorResponse('PROFILE_NOT_FOUND', 'Profile not found.', 404, $correlationId);
        }
    }

    private function errorResponse(string $code, string $message, int $status, string $correlationId, array $details = []): JsonResponse
    {
        return response()->json([
            'success'        => false,
            'data'           => null,
            'error'          => ['code' => $code, 'message' => $message, 'details' => $details],
            'correlation_id' => $correlationId,
            'meta'           => null,
        ], $status, ['X-Correlation-ID' => $correlationId]);
    }
}
