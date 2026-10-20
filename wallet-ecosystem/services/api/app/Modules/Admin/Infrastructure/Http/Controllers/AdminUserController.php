<?php

declare(strict_types=1);

namespace App\Modules\Admin\Infrastructure\Http\Controllers;

use App\Modules\Admin\Application\Services\UserAdminService;
use App\Modules\Admin\Infrastructure\Http\Requests\SearchUsersRequest;
use App\Modules\Admin\Infrastructure\Http\Requests\UserActionRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class AdminUserController extends Controller
{
    public function __construct(
        private readonly UserAdminService $service,
    ) {}

    public function list(SearchUsersRequest $request): JsonResponse
    {
        $paginator = $this->service->searchUsers(
            filters: $request->validated(),
            page:    (int) $request->query('page', 1),
            perPage: (int) $request->query('per_page', 20),
        );

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'last_page'    => $paginator->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, string $userId): JsonResponse
    {
        try {
            $profile = $this->service->getUserProfile($userId);
        } catch (\DomainException $e) {
            if ($e->getMessage() === 'USER_NOT_FOUND') {
                return response()->json(['message' => 'User not found'], 404);
            }
            throw $e;
        }

        return response()->json(['data' => $profile]);
    }

    public function suspend(UserActionRequest $request, string $userId): JsonResponse
    {
        try {
            $this->service->suspendUser(
                userId:  $userId,
                reason:  $request->validated('reason'),
                actorId: $request->user()->id,
                ip:      $request->ip(),
            );
        } catch (\DomainException $e) {
            return match ($e->getMessage()) {
                'USER_NOT_FOUND'      => response()->json(['message' => 'User not found'], 404),
                'USER_ALREADY_SUSPENDED' => response()->json(['message' => 'User is already suspended'], 409),
                default               => throw $e,
            };
        }

        return response()->json(['data' => ['action_type' => 'suspend_user', 'user_id' => $userId]]);
    }

    public function reactivate(UserActionRequest $request, string $userId): JsonResponse
    {
        try {
            $this->service->reactivateUser(
                userId:  $userId,
                reason:  $request->validated('reason'),
                actorId: $request->user()->id,
                ip:      $request->ip(),
            );
        } catch (\DomainException $e) {
            return match ($e->getMessage()) {
                'USER_NOT_FOUND'   => response()->json(['message' => 'User not found'], 404),
                'USER_ALREADY_ACTIVE' => response()->json(['message' => 'User is already active'], 409),
                default            => throw $e,
            };
        }

        return response()->json(['data' => ['action_type' => 'reactivate_user', 'user_id' => $userId]]);
    }

    public function resetAuth(UserActionRequest $request, string $userId): JsonResponse
    {
        try {
            $this->service->resetUserAuth(
                userId:  $userId,
                reason:  $request->validated('reason'),
                actorId: $request->user()->id,
                ip:      $request->ip(),
            );
        } catch (\DomainException $e) {
            if ($e->getMessage() === 'USER_NOT_FOUND') {
                return response()->json(['message' => 'User not found'], 404);
            }
            throw $e;
        }

        return response()->json(['data' => ['action_type' => 'reset_auth', 'user_id' => $userId]]);
    }

    public function sessions(Request $request, string $userId): JsonResponse
    {
        $sessions = $this->service->listSessions($userId);
        return response()->json(['data' => $sessions]);
    }

    public function terminateSession(UserActionRequest $request, string $userId, string $sessionId): JsonResponse
    {
        try {
            $this->service->terminateSession(
                userId:    $userId,
                sessionId: $sessionId,
                reason:    $request->validated('reason'),
                actorId:   $request->user()->id,
                ip:        $request->ip(),
            );
        } catch (\DomainException $e) {
            return match ($e->getMessage()) {
                'SESSION_NOT_FOUND'     => response()->json(['message' => 'Session not found'], 404),
                'SESSION_ALREADY_REVOKED' => response()->json(['message' => 'Session is already revoked'], 409),
                default                 => throw $e,
            };
        }

        return response()->json(['data' => ['action_type' => 'terminate_session', 'session_id' => $sessionId]]);
    }
}
