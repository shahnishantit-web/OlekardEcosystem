<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http\Controllers;

use App\Modules\Identity\Application\Commands\AcknowledgeNewDeviceCommand;
use App\Modules\Identity\Application\Commands\BlockDeviceCommand;
use App\Modules\Identity\Application\Commands\GenerateDeviceFingerprintCommand;
use App\Modules\Identity\Application\Commands\GetDeviceCommand;
use App\Modules\Identity\Application\Commands\ListDevicesCommand;
use App\Modules\Identity\Application\Commands\RegisterExplicitDeviceCommand;
use App\Modules\Identity\Application\Commands\RemoveDeviceCommand;
use App\Modules\Identity\Application\Commands\TrustDeviceCommand;
use App\Modules\Identity\Application\Handlers\AcknowledgeNewDeviceHandler;
use App\Modules\Identity\Application\Handlers\BlockDeviceHandler;
use App\Modules\Identity\Application\Handlers\GenerateDeviceFingerprintHandler;
use App\Modules\Identity\Application\Handlers\GetDeviceHandler;
use App\Modules\Identity\Application\Handlers\ListDevicesHandler;
use App\Modules\Identity\Application\Handlers\RegisterExplicitDeviceHandler;
use App\Modules\Identity\Application\Handlers\RemoveDeviceHandler;
use App\Modules\Identity\Application\Handlers\TrustDeviceHandler;
use App\Modules\Identity\Domain\Exceptions\AcknowledgmentWindowExpiredException;
use App\Modules\Identity\Domain\Exceptions\DeviceAlreadyAcknowledgedException;
use App\Modules\Identity\Domain\Exceptions\DeviceAlreadyBlockedException;
use App\Modules\Identity\Domain\Exceptions\DeviceBlockedException;
use App\Modules\Identity\Domain\Exceptions\DeviceLimitExceededException;
use App\Modules\Identity\Domain\Exceptions\InsufficientFingerprintEntropyException;
use App\Modules\Identity\Domain\Exceptions\InvalidStatusTransitionException;
use App\Modules\Identity\Infrastructure\Http\Requests\AcknowledgeNewDeviceRequest;
use App\Modules\Identity\Infrastructure\Http\Requests\BlockDeviceRequest;
use App\Modules\Identity\Infrastructure\Http\Requests\GenerateFingerprintRequest;
use App\Modules\Identity\Infrastructure\Http\Requests\RegisterDeviceRequest;
use App\Modules\Identity\Infrastructure\Http\Requests\TrustDeviceRequest;
use App\Modules\Identity\Infrastructure\Security\IpHasher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class DeviceManagementController extends Controller
{
    public function __construct(
        private readonly GenerateDeviceFingerprintHandler $fingerprintHandler,
        private readonly RegisterExplicitDeviceHandler    $registerHandler,
        private readonly TrustDeviceHandler               $trustHandler,
        private readonly ListDevicesHandler               $listHandler,
        private readonly GetDeviceHandler                 $getHandler,
        private readonly RemoveDeviceHandler              $removeHandler,
        private readonly AcknowledgeNewDeviceHandler      $acknowledgeHandler,
        private readonly BlockDeviceHandler               $blockHandler,
        private readonly IpHasher                         $ipHasher,
    ) {}

    /**
     * POST /v1/devices/fingerprint
     *
     * @OA\Post(
     *     path="/v1/devices/fingerprint",
     *     operationId="generateDeviceFingerprint",
     *     summary="Compute a SHA-256 device fingerprint hash from platform attributes",
     *     tags={"E03 Device Management"},
     *     security={{"BearerAuth":{}}},
     *     @OA\RequestBody(required=true,
     *         @OA\JsonContent(
     *             required={"platform","attributes"},
     *             @OA\Property(property="platform", type="string", enum={"ios","android","web"}),
     *             @OA\Property(property="attributes", type="object", description="Platform-specific attribute map")
     *         )
     *     ),
     *     @OA\Response(response=200, description="Fingerprint hash computed",
     *         @OA\JsonContent(@OA\Property(property="data",type="object",
     *             @OA\Property(property="fingerprint_hash",type="string",example="a3f8c2e1d4b7...")))),
     *     @OA\Response(response=400, description="INSUFFICIENT_ENTROPY or INVALID_ATTRIBUTE_FORMAT"),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function fingerprint(GenerateFingerprintRequest $request): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());

        try {
            $result = $this->fingerprintHandler->handle(new GenerateDeviceFingerprintCommand(
                userId:        (string) $request->attributes->get('identity.user_id'),
                platform:      (string) $request->validated('platform'),
                attributes:    (array) $request->validated('attributes'),
                actorType:     (string) $request->attributes->get('identity.actor_type', 'customer'),
                correlationId: $correlationId,
                ipHash:        $this->ipHasher->hash($request->ip() ?? '0.0.0.0'),
            ));
        } catch (InsufficientFingerprintEntropyException) {
            return $this->errorResponse('INSUFFICIENT_ENTROPY', 'Submitted attributes do not provide sufficient entropy.', 400, $correlationId);
        }

        return $this->successResponse($result, 200, $correlationId);
    }

    /**
     * POST /v1/devices
     *
     * @OA\Post(
     *     path="/v1/devices",
     *     operationId="registerDevice",
     *     summary="Register a new device for the authenticated user",
     *     tags={"E03 Device Management"},
     *     security={{"BearerAuth":{}}},
     *     @OA\Parameter(name="Idempotency-Key",in="header",required=true,@OA\Schema(type="string",format="uuid")),
     *     @OA\RequestBody(required=true,
     *         @OA\JsonContent(
     *             required={"device_name","platform","fingerprint_hash"},
     *             @OA\Property(property="device_name",type="string",maxLength=100),
     *             @OA\Property(property="platform",type="string",enum={"ios","android","web"}),
     *             @OA\Property(property="fingerprint_hash",type="string",minLength=64,maxLength=64)
     *         )
     *     ),
     *     @OA\Response(response=201, description="New device registered",
     *         @OA\JsonContent(@OA\Property(property="data",type="object",
     *             @OA\Property(property="device_id",type="string",format="uuid"),
     *             @OA\Property(property="status",type="string",example="pending_verification"),
     *             @OA\Property(property="challenge_id",type="string",format="uuid",nullable=true)))),
     *     @OA\Response(response=200, description="Existing device (idempotent replay or same fingerprint)"),
     *     @OA\Response(response=400, description="MISSING_IDEMPOTENCY_KEY or INVALID_FINGERPRINT_FORMAT"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=409, description="DEVICE_LIMIT_EXCEEDED")
     * )
     */
    public function register(RegisterDeviceRequest $request): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Str::uuid());
        $idempotencyKey = (string) ($request->header('Idempotency-Key') ?? '');

        if ($idempotencyKey === '') {
            return $this->errorResponse('MISSING_IDEMPOTENCY_KEY', 'Idempotency-Key header is required.', 400, $correlationId);
        }

        try {
            $result = $this->registerHandler->handle(new RegisterExplicitDeviceCommand(
                userId:         (string) $request->attributes->get('identity.user_id'),
                deviceName:     (string) $request->validated('device_name'),
                platform:       (string) $request->validated('platform'),
                fingerprintHash:(string) $request->validated('fingerprint_hash'),
                actorType:      (string) $request->attributes->get('identity.actor_type', 'customer'),
                correlationId:  $correlationId,
                ipHash:         $this->ipHasher->hash($request->ip() ?? '0.0.0.0'),
                idempotencyKey: $idempotencyKey,
            ));
        } catch (DeviceLimitExceededException) {
            return $this->errorResponse('DEVICE_LIMIT_EXCEEDED', 'Maximum device limit reached.', 409, $correlationId);
        }

        $statusCode = isset($result['_idempotent']) ? 200 : 201;

        return $this->successResponse($result, $statusCode, $correlationId);
    }

    /**
     * POST /v1/devices/{id}/trust
     *
     * @OA\Post(
     *     path="/v1/devices/{id}/trust",
     *     operationId="trustDevice",
     *     summary="Mark a pending_verification device as trusted",
     *     tags={"E03 Device Management"},
     *     security={{"BearerAuth":{}}},
     *     @OA\Parameter(name="id",in="path",required=true,@OA\Schema(type="string",format="uuid")),
     *     @OA\Parameter(name="Idempotency-Key",in="header",required=false,@OA\Schema(type="string",format="uuid")),
     *     @OA\RequestBody(required=false,
     *         @OA\JsonContent(@OA\Property(property="reason",type="string",nullable=true))),
     *     @OA\Response(response=200, description="Device trusted"),
     *     @OA\Response(response=400, description="INVALID_STATUS_TRANSITION"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="DEVICE_BLOCKED or DEVICE_FORBIDDEN"),
     *     @OA\Response(response=404, description="DEVICE_NOT_FOUND")
     * )
     */
    public function trust(TrustDeviceRequest $request, string $id): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Str::uuid());
        $idempotencyKey = (string) ($request->header('Idempotency-Key') ?? Str::uuid());

        try {
            $result = $this->trustHandler->handle(new TrustDeviceCommand(
                userId:         (string) $request->attributes->get('identity.user_id'),
                deviceId:       $id,
                reason:         $request->validated('reason'),
                actorType:      (string) $request->attributes->get('identity.actor_type', 'customer'),
                correlationId:  $correlationId,
                ipHash:         $this->ipHasher->hash($request->ip() ?? '0.0.0.0'),
                idempotencyKey: $idempotencyKey,
            ));
        } catch (DeviceBlockedException) {
            return $this->errorResponse('DEVICE_BLOCKED', 'Device is blocked; only an admin can unblock it.', 403, $correlationId);
        } catch (InvalidStatusTransitionException $e) {
            return $this->errorResponse('INVALID_STATUS_TRANSITION', $e->getMessage(), 400, $correlationId);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'DEVICE_FORBIDDEN') {
                return $this->errorResponse('DEVICE_FORBIDDEN', 'Access denied.', 403, $correlationId);
            }
            if ($e->getMessage() === 'DEVICE_NOT_FOUND') {
                return $this->errorResponse('DEVICE_NOT_FOUND', 'Device not found.', 404, $correlationId);
            }
            throw $e;
        }

        return $this->successResponse($result, 200, $correlationId);
    }

    /**
     * GET /v1/devices
     *
     * @OA\Get(
     *     path="/v1/devices",
     *     operationId="listDevices",
     *     summary="List devices belonging to the authenticated user",
     *     tags={"E03 Device Management"},
     *     security={{"BearerAuth":{}}},
     *     @OA\Parameter(name="page",in="query",required=false,@OA\Schema(type="integer",default=1)),
     *     @OA\Parameter(name="per_page",in="query",required=false,@OA\Schema(type="integer",default=20,maximum=100)),
     *     @OA\Parameter(name="status",in="query",required=false,@OA\Schema(type="string",enum={"pending_verification","trusted","blocked","revoked"})),
     *     @OA\Parameter(name="platform",in="query",required=false,@OA\Schema(type="string",enum={"ios","android","web"})),
     *     @OA\Response(response=200, description="Paginated device list",
     *         @OA\JsonContent(@OA\Property(property="data",type="array",@OA\Items(type="object")),
     *             @OA\Property(property="meta",type="object",
     *                 @OA\Property(property="total",type="integer"),
     *                 @OA\Property(property="page",type="integer"),
     *                 @OA\Property(property="per_page",type="integer")))),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());

        $result = $this->listHandler->handle(new ListDevicesCommand(
            userId:   (string) $request->attributes->get('identity.user_id'),
            page:     (int) $request->query('page', 1),
            perPage:  min((int) $request->query('per_page', 20), 100),
            status:   $request->query('status') ?: null,
            platform: $request->query('platform') ?: null,
        ));

        // Flatten paginated result: expose data[] and meta{} at top level
        $meta = $result['meta'];
        return response()->json([
            'success' => true,
            'data'    => array_map(static fn ($d) => [
                'id'           => $d->id,
                'device_name'  => $d->device_name,
                'platform'     => $d->platform,
                'status'       => $d->status,
                'trusted_at'   => $d->trusted_at,
                'last_seen_at' => $d->last_seen_at,
                'created_at'   => $d->created_at,
            ], $result['data']),
            'meta'    => [
                'total'        => $meta['total'],
                'current_page' => $meta['page'],
                'per_page'     => $meta['per_page'],
                'last_page'    => $meta['last_page'],
            ],
        ], 200, ['X-Correlation-ID' => $correlationId]);
    }

    /**
     * GET /v1/devices/{id}
     *
     * @OA\Get(
     *     path="/v1/devices/{id}",
     *     operationId="getDevice",
     *     summary="Get a single device owned by the authenticated user",
     *     tags={"E03 Device Management"},
     *     security={{"BearerAuth":{}}},
     *     @OA\Parameter(name="id",in="path",required=true,@OA\Schema(type="string",format="uuid")),
     *     @OA\Response(response=200, description="Device detail (fingerprint_hash excluded)"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="DEVICE_FORBIDDEN"),
     *     @OA\Response(response=404, description="DEVICE_NOT_FOUND")
     * )
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());

        try {
            $result = $this->getHandler->handle(new GetDeviceCommand(
                userId:   (string) $request->attributes->get('identity.user_id'),
                deviceId: $id,
            ));
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'DEVICE_FORBIDDEN') {
                return $this->errorResponse('DEVICE_FORBIDDEN', 'Access denied.', 403, $correlationId);
            }
            if ($e->getMessage() === 'DEVICE_NOT_FOUND') {
                return $this->errorResponse('DEVICE_NOT_FOUND', 'Device not found.', 404, $correlationId);
            }
            throw $e;
        }

        return $this->successResponse($result, 200, $correlationId);
    }

    /**
     * DELETE /v1/devices/{id}
     *
     * @OA\Delete(
     *     path="/v1/devices/{id}",
     *     operationId="removeDevice",
     *     summary="Remove (revoke) a device and invalidate all its sessions",
     *     tags={"E03 Device Management"},
     *     security={{"BearerAuth":{}}},
     *     @OA\Parameter(name="id",in="path",required=true,@OA\Schema(type="string",format="uuid")),
     *     @OA\Parameter(name="Idempotency-Key",in="header",required=false,@OA\Schema(type="string",format="uuid")),
     *     @OA\Response(response=200, description="Device removed",
     *         @OA\JsonContent(@OA\Property(property="data",type="object",
     *             @OA\Property(property="sessions_revoked",type="integer")))),
     *     @OA\Response(response=400, description="INVALID_STATUS_TRANSITION"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="DEVICE_FORBIDDEN"),
     *     @OA\Response(response=404, description="DEVICE_NOT_FOUND")
     * )
     */
    public function remove(Request $request, string $id): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Str::uuid());
        $idempotencyKey = (string) ($request->header('Idempotency-Key') ?? Str::uuid());

        try {
            $result = $this->removeHandler->handle(new RemoveDeviceCommand(
                userId:         (string) $request->attributes->get('identity.user_id'),
                deviceId:       $id,
                isAdminAction:  false,
                adminActorId:   null,
                actorType:      (string) $request->attributes->get('identity.actor_type', 'customer'),
                correlationId:  $correlationId,
                ipHash:         $this->ipHasher->hash($request->ip() ?? '0.0.0.0'),
                idempotencyKey: $idempotencyKey,
            ));
        } catch (InvalidStatusTransitionException $e) {
            return $this->errorResponse('INVALID_STATUS_TRANSITION', $e->getMessage(), 400, $correlationId);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'DEVICE_FORBIDDEN') {
                return $this->errorResponse('DEVICE_FORBIDDEN', 'Access denied.', 403, $correlationId);
            }
            if ($e->getMessage() === 'DEVICE_NOT_FOUND') {
                return $this->errorResponse('DEVICE_NOT_FOUND', 'Device not found.', 404, $correlationId);
            }
            throw $e;
        }

        return $this->successResponse($result, 200, $correlationId);
    }

    /**
     * POST /v1/devices/{id}/acknowledge
     *
     * @OA\Post(
     *     path="/v1/devices/{id}/acknowledge",
     *     operationId="acknowledgeNewDevice",
     *     summary="Acknowledge a new-device notification (approve or deny the device)",
     *     tags={"E03 Device Management"},
     *     security={{"BearerAuth":{}}},
     *     @OA\Parameter(name="id",in="path",required=true,@OA\Schema(type="string",format="uuid")),
     *     @OA\Parameter(name="Idempotency-Key",in="header",required=false,@OA\Schema(type="string",format="uuid")),
     *     @OA\RequestBody(required=true,
     *         @OA\JsonContent(required={"acknowledged"},
     *             @OA\Property(property="acknowledged",type="boolean",description="true = trust device; false = block device"))),
     *     @OA\Response(response=200, description="Acknowledgment recorded"),
     *     @OA\Response(response=400, description="ACKNOWLEDGMENT_WINDOW_EXPIRED or DEVICE_ALREADY_ACKNOWLEDGED"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="DEVICE_FORBIDDEN")
     * )
     */
    public function acknowledge(AcknowledgeNewDeviceRequest $request, string $id): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Str::uuid());
        $idempotencyKey = (string) ($request->header('Idempotency-Key') ?? Str::uuid());

        try {
            $result = $this->acknowledgeHandler->handle(new AcknowledgeNewDeviceCommand(
                userId:         (string) $request->attributes->get('identity.user_id'),
                deviceId:       $id,
                acknowledged:   (bool) $request->validated('acknowledged'),
                actorType:      (string) $request->attributes->get('identity.actor_type', 'customer'),
                correlationId:  $correlationId,
                ipHash:         $this->ipHasher->hash($request->ip() ?? '0.0.0.0'),
                idempotencyKey: $idempotencyKey,
            ));
        } catch (AcknowledgmentWindowExpiredException) {
            return $this->errorResponse('ACKNOWLEDGMENT_WINDOW_EXPIRED', 'Acknowledgment window has expired.', 400, $correlationId);
        } catch (DeviceAlreadyAcknowledgedException) {
            return $this->errorResponse('DEVICE_ALREADY_ACKNOWLEDGED', 'Device notification already acknowledged.', 400, $correlationId);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'DEVICE_FORBIDDEN') {
                return $this->errorResponse('DEVICE_FORBIDDEN', 'Access denied.', 403, $correlationId);
            }
            throw $e;
        }

        return $this->successResponse($result, 200, $correlationId);
    }

    /**
     * POST /v1/devices/{id}/block
     *
     * @OA\Post(
     *     path="/v1/devices/{id}/block",
     *     operationId="blockDevice",
     *     summary="Block a device (self-service; blocks login from this device)",
     *     tags={"E03 Device Management"},
     *     security={{"BearerAuth":{}}},
     *     @OA\Parameter(name="id",in="path",required=true,@OA\Schema(type="string",format="uuid")),
     *     @OA\Parameter(name="Idempotency-Key",in="header",required=false,@OA\Schema(type="string",format="uuid")),
     *     @OA\RequestBody(required=false,
     *         @OA\JsonContent(@OA\Property(property="reason",type="string",nullable=true))),
     *     @OA\Response(response=200, description="Device blocked"),
     *     @OA\Response(response=400, description="DEVICE_ALREADY_BLOCKED"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="DEVICE_FORBIDDEN"),
     *     @OA\Response(response=404, description="DEVICE_NOT_FOUND")
     * )
     */
    public function block(BlockDeviceRequest $request, string $id): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Str::uuid());
        $idempotencyKey = (string) ($request->header('Idempotency-Key') ?? Str::uuid());

        try {
            $result = $this->blockHandler->handle(new BlockDeviceCommand(
                userId:         (string) $request->attributes->get('identity.user_id'),
                deviceId:       $id,
                reason:         $request->validated('reason'),
                isAdminAction:  false,
                adminActorId:   null,
                actorType:      (string) $request->attributes->get('identity.actor_type', 'customer'),
                correlationId:  $correlationId,
                ipHash:         $this->ipHasher->hash($request->ip() ?? '0.0.0.0'),
                idempotencyKey: $idempotencyKey,
            ));
        } catch (DeviceAlreadyBlockedException) {
            return $this->errorResponse('DEVICE_ALREADY_BLOCKED', 'Device is already blocked.', 400, $correlationId);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'DEVICE_FORBIDDEN') {
                return $this->errorResponse('DEVICE_FORBIDDEN', 'Access denied.', 403, $correlationId);
            }
            if ($e->getMessage() === 'DEVICE_NOT_FOUND') {
                return $this->errorResponse('DEVICE_NOT_FOUND', 'Device not found.', 404, $correlationId);
            }
            throw $e;
        }

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
