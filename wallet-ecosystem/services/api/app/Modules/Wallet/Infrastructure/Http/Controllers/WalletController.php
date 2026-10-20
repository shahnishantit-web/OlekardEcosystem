<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Infrastructure\Http\Controllers;

use App\Modules\Wallet\Application\Services\WalletProvisioningService;
use App\Modules\Wallet\Application\Services\WalletService;
use App\Modules\Wallet\Domain\Enums\WalletStatus;
use App\Modules\Wallet\Domain\Exceptions\InvalidWalletTransitionException;
use App\Modules\Wallet\Domain\Exceptions\WalletAlreadyExistsException;
use App\Modules\Wallet\Domain\Exceptions\WalletFrozenException;
use App\Modules\Wallet\Domain\Exceptions\WalletHasBalanceException;
use App\Modules\Wallet\Domain\Exceptions\WalletNotTransactableException;
use App\Modules\Wallet\Infrastructure\Http\Requests\CloseWalletRequest;
use App\Modules\Wallet\Infrastructure\Http\Requests\CreateWalletRequest;
use App\Modules\Wallet\Infrastructure\Http\Requests\FreezeWalletRequest;
use App\Modules\Wallet\Infrastructure\Http\Requests\UnfreezeWalletRequest;
use App\Modules\Wallet\Infrastructure\Http\Requests\UpdateWalletStatusRequest;
use App\Modules\Wallet\Infrastructure\Http\Resources\WalletResource;
use App\Modules\Wallet\Infrastructure\Repositories\EloquentWalletRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class WalletController extends Controller
{
    public function __construct(
        private readonly WalletProvisioningService $provisioningService,
        private readonly WalletService             $walletService,
        private readonly EloquentWalletRepository  $walletRepository,
    ) {}

    /** POST /v1/wallets — Admin creates wallet manually */
    public function create(CreateWalletRequest $request): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Str::uuid());
        $idempotencyKey = $request->header('Idempotency-Key');
        $actorId        = $request->attributes->get('identity.user_id');

        if (!$idempotencyKey) {
            return $this->error('VALIDATION_ERROR', 'Idempotency-Key header is required.', 422, $correlationId);
        }

        try {
            $wallet = $this->provisioningService->provisionForUser(
                userId:        $request->validated('user_id'),
                correlationId: $correlationId,
                actorId:       $actorId,
            );

            $wallet->load('pockets');

            return $this->success(new WalletResource($wallet), 201, $correlationId);
        } catch (WalletAlreadyExistsException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->httpStatus, $correlationId);
        }
    }

    /** GET /v1/wallets/my — authenticated user's own wallet */
    public function my(Request $request): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $userId        = $request->attributes->get('identity.user_id');

        $wallet = $this->walletRepository->findActiveByOwnerId($userId);

        if ($wallet === null) {
            return $this->error('WALLET_NOT_FOUND', 'No wallet found for the authenticated user.', 404, $correlationId);
        }

        $wallet->load('pockets');

        return $this->success(new WalletResource($wallet), 200, $correlationId);
    }

    /** GET /v1/wallets/{id} — IDOR-safe wallet lookup */
    public function show(Request $request, string $id): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $callerId      = $request->attributes->get('identity.user_id');
        $hasReadAny    = $request->attributes->get('identity.permissions', []);

        $wallet = $this->walletRepository->findById($id);

        if ($wallet === null) {
            return $this->error('WALLET_NOT_FOUND', 'Wallet not found.', 404, $correlationId);
        }

        // IDOR prevention: customers without wallet.read.any get 404 on other users' wallets
        $canReadAny = in_array('wallet.read.any', (array) $hasReadAny, true);
        if (!$canReadAny && $wallet->owner_id !== $callerId) {
            return $this->error('WALLET_NOT_FOUND', 'Wallet not found.', 404, $correlationId);
        }

        $wallet->load('pockets');

        return $this->success(new WalletResource($wallet), 200, $correlationId);
    }

    /** PATCH /v1/wallets/{id}/status — Admin lifecycle management */
    public function updateStatus(UpdateWalletStatusRequest $request, string $id): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Str::uuid());
        $idempotencyKey = $request->header('Idempotency-Key');
        $actorId        = $request->attributes->get('identity.user_id');

        if (!$idempotencyKey) {
            return $this->error('VALIDATION_ERROR', 'Idempotency-Key header is required.', 422, $correlationId);
        }

        try {
            $wallet = $this->walletService->transitionStatus(
                walletId:       $id,
                to:             WalletStatus::from($request->validated('status')),
                reason:         $request->validated('reason'),
                actorId:        $actorId,
                correlationId:  $correlationId,
            );

            $wallet->load('pockets');

            return $this->success(new WalletResource($wallet), 200, $correlationId);
        } catch (InvalidWalletTransitionException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->httpStatus, $correlationId);
        }
    }

    /** POST /v1/wallets/{id}/freeze */
    public function freeze(FreezeWalletRequest $request, string $id): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Str::uuid());
        $idempotencyKey = $request->header('Idempotency-Key');
        $actorId        = $request->attributes->get('identity.user_id');

        if (!$idempotencyKey) {
            return $this->error('VALIDATION_ERROR', 'Idempotency-Key header is required.', 422, $correlationId);
        }

        try {
            $wallet = $this->walletService->freeze(
                walletId:      $id,
                reason:        $request->validated('reason'),
                actorId:       $actorId,
                correlationId: $correlationId,
            );

            $wallet->load('pockets');

            return $this->success(new WalletResource($wallet), 200, $correlationId);
        } catch (InvalidWalletTransitionException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->httpStatus, $correlationId);
        }
    }

    /** POST /v1/wallets/{id}/unfreeze */
    public function unfreeze(UnfreezeWalletRequest $request, string $id): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Str::uuid());
        $idempotencyKey = $request->header('Idempotency-Key');
        $actorId        = $request->attributes->get('identity.user_id');

        if (!$idempotencyKey) {
            return $this->error('VALIDATION_ERROR', 'Idempotency-Key header is required.', 422, $correlationId);
        }

        try {
            $wallet = $this->walletService->unfreeze(
                walletId:      $id,
                reason:        $request->validated('reason'),
                actorId:       $actorId,
                correlationId: $correlationId,
            );

            $wallet->load('pockets');

            return $this->success(new WalletResource($wallet), 200, $correlationId);
        } catch (InvalidWalletTransitionException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->httpStatus, $correlationId);
        }
    }

    /** POST /v1/wallets/{id}/close */
    public function close(CloseWalletRequest $request, string $id): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Str::uuid());
        $idempotencyKey = $request->header('Idempotency-Key');
        $actorId        = $request->attributes->get('identity.user_id');

        if (!$idempotencyKey) {
            return $this->error('VALIDATION_ERROR', 'Idempotency-Key header is required.', 422, $correlationId);
        }

        try {
            $wallet = $this->walletService->close(
                walletId:      $id,
                reason:        $request->validated('reason'),
                actorId:       $actorId,
                correlationId: $correlationId,
            );

            $wallet->load('pockets');

            return $this->success(new WalletResource($wallet), 200, $correlationId);
        } catch (WalletHasBalanceException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->httpStatus, $correlationId);
        } catch (InvalidWalletTransitionException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->httpStatus, $correlationId);
        }
    }

    private function success(WalletResource $resource, int $status, string $correlationId): JsonResponse
    {
        return response()->json([
            'success'        => true,
            'data'           => $resource->toArray(request()),
            'error'          => null,
            'correlation_id' => $correlationId,
        ], $status, ['X-Correlation-ID' => $correlationId]);
    }

    private function error(string $code, string $message, int $status, string $correlationId, array $details = []): JsonResponse
    {
        return response()->json([
            'success'        => false,
            'data'           => null,
            'error'          => ['code' => $code, 'message' => $message, 'details' => $details],
            'correlation_id' => $correlationId,
        ], $status, ['X-Correlation-ID' => $correlationId]);
    }
}
