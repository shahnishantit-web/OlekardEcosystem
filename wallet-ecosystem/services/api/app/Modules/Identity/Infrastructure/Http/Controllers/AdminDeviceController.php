<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http\Controllers;

use App\Modules\Identity\Application\Commands\BlockDeviceCommand;
use App\Modules\Identity\Application\Commands\ListDevicesCommand;
use App\Modules\Identity\Application\Commands\RemoveDeviceCommand;
use App\Modules\Identity\Application\Commands\UnblockDeviceCommand;
use App\Modules\Identity\Application\Handlers\BlockDeviceHandler;
use App\Modules\Identity\Application\Handlers\ListDevicesHandler;
use App\Modules\Identity\Application\Handlers\RemoveDeviceHandler;
use App\Modules\Identity\Application\Handlers\UnblockDeviceHandler;
use App\Modules\Identity\Domain\Contracts\DeviceSecurityEventRepositoryInterface;
use App\Modules\Identity\Domain\Exceptions\DeviceAlreadyBlockedException;
use App\Modules\Identity\Domain\Exceptions\InvalidStatusTransitionException;
use App\Modules\Identity\Infrastructure\Http\Requests\AdminBlockDeviceRequest;
use App\Modules\Identity\Infrastructure\Http\Requests\AdminUnblockDeviceRequest;
use App\Modules\Identity\Infrastructure\Security\IpHasher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class AdminDeviceController extends Controller
{
    public function __construct(
        private readonly ListDevicesHandler                    $listHandler,
        private readonly RemoveDeviceHandler                   $removeHandler,
        private readonly BlockDeviceHandler                    $blockHandler,
        private readonly UnblockDeviceHandler                  $unblockHandler,
        private readonly DeviceSecurityEventRepositoryInterface $securityEvents,
        private readonly IpHasher                              $ipHasher,
    ) {}

    /**
     * GET /v1/admin/devices/{userId}
     *
     * @OA\Get(
     *     path="/v1/admin/users/{userId}/devices",
     *     operationId="adminListDevices",
     *     summary="Admin: list all devices for a given user",
     *     tags={"E03 Admin Device Management"},
     *     security={{"BearerAuth":{}}},
     *     @OA\Parameter(name="userId",in="path",required=true,@OA\Schema(type="string",format="uuid")),
     *     @OA\Parameter(name="page",in="query",required=false,@OA\Schema(type="integer",default=1)),
     *     @OA\Parameter(name="per_page",in="query",required=false,@OA\Schema(type="integer",default=20,maximum=100)),
     *     @OA\Parameter(name="status",in="query",required=false,@OA\Schema(type="string")),
     *     @OA\Parameter(name="platform",in="query",required=false,@OA\Schema(type="string")),
     *     @OA\Response(response=200, description="Paginated device list for user"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Insufficient admin privileges")
     * )
     */
    public function index(Request $request, string $userId): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());

        $result = $this->listHandler->handle(new ListDevicesCommand(
            userId:   $userId,
            page:     (int) $request->query('page', 1),
            perPage:  min((int) $request->query('per_page', 20), 100),
            status:   $request->query('status') ?: null,
            platform: $request->query('platform') ?: null,
        ));

        return $this->successResponse($result, 200, $correlationId);
    }

    /**
     * DELETE /v1/admin/devices/{id}
     *
     * @OA\Delete(
     *     path="/v1/admin/users/{userId}/devices/{id}",
     *     operationId="adminRemoveDevice",
     *     summary="Admin: forcibly remove (revoke) any device",
     *     tags={"E03 Admin Device Management"},
     *     security={{"BearerAuth":{}}},
     *     @OA\Parameter(name="userId",in="path",required=true,@OA\Schema(type="string",format="uuid")),
     *     @OA\Parameter(name="id",in="path",required=true,@OA\Schema(type="string",format="uuid")),
     *     @OA\Parameter(name="Idempotency-Key",in="header",required=false,@OA\Schema(type="string",format="uuid")),
     *     @OA\Response(response=200, description="Device removed"),
     *     @OA\Response(response=400, description="INVALID_STATUS_TRANSITION"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="DEVICE_NOT_FOUND")
     * )
     */
    public function remove(Request $request, string $id): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Str::uuid());
        $idempotencyKey = (string) ($request->header('Idempotency-Key') ?? Str::uuid());
        $adminActorId   = (string) $request->attributes->get('identity.user_id');

        try {
            $result = $this->removeHandler->handle(new RemoveDeviceCommand(
                userId:         $adminActorId,
                deviceId:       $id,
                isAdminAction:  true,
                adminActorId:   $adminActorId,
                actorType:      'admin',
                correlationId:  $correlationId,
                ipHash:         $this->ipHasher->hash($request->ip() ?? '0.0.0.0'),
                idempotencyKey: $idempotencyKey,
            ));
        } catch (InvalidStatusTransitionException $e) {
            return $this->errorResponse('INVALID_STATUS_TRANSITION', $e->getMessage(), 400, $correlationId);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'DEVICE_NOT_FOUND') {
                return $this->errorResponse('DEVICE_NOT_FOUND', 'Device not found.', 404, $correlationId);
            }
            throw $e;
        }

        return $this->successResponse($result, 200, $correlationId);
    }

    /**
     * POST /v1/admin/devices/{id}/block
     *
     * @OA\Post(
     *     path="/v1/admin/users/{userId}/devices/{id}/block",
     *     operationId="adminBlockDevice",
     *     summary="Admin: block a device for a specific user",
     *     tags={"E03 Admin Device Management"},
     *     security={{"BearerAuth":{}}},
     *     @OA\Parameter(name="userId",in="path",required=true,@OA\Schema(type="string",format="uuid")),
     *     @OA\Parameter(name="id",in="path",required=true,@OA\Schema(type="string",format="uuid")),
     *     @OA\Parameter(name="Idempotency-Key",in="header",required=false,@OA\Schema(type="string",format="uuid")),
     *     @OA\RequestBody(required=true,
     *         @OA\JsonContent(required={"reason"},@OA\Property(property="reason",type="string"))),
     *     @OA\Response(response=200, description="Device blocked"),
     *     @OA\Response(response=400, description="DEVICE_ALREADY_BLOCKED"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="DEVICE_NOT_FOUND")
     * )
     */
    public function block(AdminBlockDeviceRequest $request, string $id): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Str::uuid());
        $idempotencyKey = (string) ($request->header('Idempotency-Key') ?? Str::uuid());
        $adminActorId   = (string) $request->attributes->get('identity.user_id');

        try {
            $result = $this->blockHandler->handle(new BlockDeviceCommand(
                userId:         $adminActorId,
                deviceId:       $id,
                reason:         $request->validated('reason'),
                isAdminAction:  true,
                adminActorId:   $adminActorId,
                actorType:      'admin',
                correlationId:  $correlationId,
                ipHash:         $this->ipHasher->hash($request->ip() ?? '0.0.0.0'),
                idempotencyKey: $idempotencyKey,
            ));
        } catch (DeviceAlreadyBlockedException) {
            return $this->errorResponse('DEVICE_ALREADY_BLOCKED', 'Device is already blocked.', 400, $correlationId);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'DEVICE_NOT_FOUND') {
                return $this->errorResponse('DEVICE_NOT_FOUND', 'Device not found.', 404, $correlationId);
            }
            throw $e;
        }

        return $this->successResponse($result, 200, $correlationId);
    }

    /**
     * POST /v1/admin/devices/{id}/unblock
     *
     * @OA\Post(
     *     path="/v1/admin/users/{userId}/devices/{id}/unblock",
     *     operationId="adminUnblockDevice",
     *     summary="Admin: unblock a previously-blocked device",
     *     tags={"E03 Admin Device Management"},
     *     security={{"BearerAuth":{}}},
     *     @OA\Parameter(name="userId",in="path",required=true,@OA\Schema(type="string",format="uuid")),
     *     @OA\Parameter(name="id",in="path",required=true,@OA\Schema(type="string",format="uuid")),
     *     @OA\Parameter(name="Idempotency-Key",in="header",required=false,@OA\Schema(type="string",format="uuid")),
     *     @OA\RequestBody(required=false,
     *         @OA\JsonContent(@OA\Property(property="reason",type="string",nullable=true))),
     *     @OA\Response(response=200, description="Device unblocked"),
     *     @OA\Response(response=400, description="INVALID_STATUS_TRANSITION"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="DEVICE_NOT_FOUND")
     * )
     */
    public function unblock(AdminUnblockDeviceRequest $request, string $id): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Str::uuid());
        $idempotencyKey = (string) ($request->header('Idempotency-Key') ?? Str::uuid());
        $adminActorId   = (string) $request->attributes->get('identity.user_id');

        try {
            $result = $this->unblockHandler->handle(new UnblockDeviceCommand(
                deviceId:     $id,
                adminActorId: $adminActorId,
                reason:       $request->validated('reason'),
                correlationId: $correlationId,
                ipHash:        $this->ipHasher->hash($request->ip() ?? '0.0.0.0'),
                idempotencyKey: $idempotencyKey,
            ));
        } catch (InvalidStatusTransitionException $e) {
            return $this->errorResponse('INVALID_STATUS_TRANSITION', $e->getMessage(), 400, $correlationId);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'DEVICE_NOT_FOUND') {
                return $this->errorResponse('DEVICE_NOT_FOUND', 'Device not found.', 404, $correlationId);
            }
            throw $e;
        }

        return $this->successResponse($result, 200, $correlationId);
    }

    /**
     * GET /v1/admin/devices/{id}/security-events
     *
     * @OA\Get(
     *     path="/v1/admin/users/{userId}/devices/{id}/security-events",
     *     operationId="adminDeviceSecurityEvents",
     *     summary="Admin: list security events for a specific device",
     *     tags={"E03 Admin Device Management"},
     *     security={{"BearerAuth":{}}},
     *     @OA\Parameter(name="userId",in="path",required=true,@OA\Schema(type="string",format="uuid")),
     *     @OA\Parameter(name="id",in="path",required=true,@OA\Schema(type="string",format="uuid")),
     *     @OA\Parameter(name="page",in="query",required=false,@OA\Schema(type="integer",default=1)),
     *     @OA\Parameter(name="per_page",in="query",required=false,@OA\Schema(type="integer",default=20,maximum=100)),
     *     @OA\Response(response=200, description="Paginated security event list"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Insufficient admin privileges")
     * )
     */
    public function securityEvents(Request $request, string $id): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());

        $result = $this->securityEvents->listForDevice(
            deviceId: $id,
            page:     (int) $request->query('page', 1),
            perPage:  min((int) $request->query('per_page', 20), 100),
        );

        return $this->successResponse($result, 200, $correlationId);
    }

    private function successResponse(array $data, int $status, string $correlationId): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data], $status, ['X-Correlation-ID' => $correlationId]);
    }

    private function errorResponse(string $code, string $message, int $status, string $correlationId): JsonResponse
    {
        return response()->json(
            ['success' => false, 'error' => ['code' => $code, 'message' => $message, 'trace_id' => $correlationId]],
            $status,
            ['X-Correlation-ID' => $correlationId],
        );
    }
}
