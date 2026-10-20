<?php

declare(strict_types=1);

namespace App\Modules\Admin\Infrastructure\Http\Controllers;

use App\Modules\Admin\Application\Services\AdminWalletAdjustmentService;
use App\Modules\Admin\Application\Services\WalletAdminService;
use App\Modules\Admin\Infrastructure\Http\Requests\ApproveAdjustmentRequest;
use App\Modules\Admin\Infrastructure\Http\Requests\CreateAdjustmentRequest;
use App\Modules\Admin\Infrastructure\Http\Requests\FreezeWalletRequest;
use App\Modules\Admin\Infrastructure\Http\Requests\RejectAdjustmentRequest;
use App\Modules\Admin\Infrastructure\Http\Requests\UserActionRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class AdminWalletController extends Controller
{
    public function __construct(
        private readonly WalletAdminService            $walletService,
        private readonly AdminWalletAdjustmentService  $adjustmentService,
    ) {}

    public function list(Request $request): JsonResponse
    {
        $paginator = $this->walletService->searchWallets(
            filters: $request->only(['q', 'status']),
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

    public function show(Request $request, string $walletId): JsonResponse
    {
        try {
            return response()->json(['data' => $this->walletService->getWallet($walletId)]);
        } catch (\DomainException $e) {
            if ($e->getMessage() === 'WALLET_NOT_FOUND') {
                return response()->json(['message' => 'Wallet not found'], 404);
            }
            throw $e;
        }
    }

    public function freeze(FreezeWalletRequest $request, string $walletId): JsonResponse
    {
        $data    = $request->validated();
        $actorId = $request->user()->id;

        if ($actorId === $data['checker_id']) {
            return response()->json(['message' => 'Checker cannot be the same as the actor (maker-checker violation)'], 422);
        }

        try {
            $hold = $this->walletService->freezeWallet(
                walletId:  $walletId,
                reason:    $data['reason'],
                makerId:   $actorId,
                checkerId: $data['checker_id'],
                holdType:  $data['hold_type'],
                ip:        $request->ip(),
            );
        } catch (\DomainException $e) {
            return match ($e->getMessage()) {
                'WALLET_NOT_FOUND'     => response()->json(['message' => 'Wallet not found'], 404),
                'WALLET_ALREADY_FROZEN' => response()->json(['message' => 'Wallet is already frozen'], 409),
                'MAKER_CHECKER_VIOLATION' => response()->json(['message' => 'Checker cannot be the maker'], 422),
                default                => throw $e,
            };
        }

        return response()->json(['data' => ['action_type' => 'freeze_wallet', 'hold_id' => $hold->id]], 201);
    }

    public function unfreeze(UserActionRequest $request, string $walletId): JsonResponse
    {
        try {
            $this->walletService->unfreezeWallet(
                walletId: $walletId,
                reason:   $request->validated('reason'),
                actorId:  $request->user()->id,
                ip:       $request->ip(),
            );
        } catch (\DomainException $e) {
            return match ($e->getMessage()) {
                'WALLET_NOT_FOUND' => response()->json(['message' => 'Wallet not found'], 404),
                'WALLET_NOT_FROZEN' => response()->json(['message' => 'Wallet is not currently frozen'], 409),
                default            => throw $e,
            };
        }

        return response()->json(['data' => ['action_type' => 'unfreeze_wallet', 'wallet_id' => $walletId]]);
    }

    public function ledger(Request $request, string $walletId): JsonResponse
    {
        try {
            $paginator = $this->walletService->getWalletLedger(
                walletId: $walletId,
                filters:  $request->only(['from', 'to', 'entry_type', 'currency']),
                page:     (int) $request->query('page', 1),
                perPage:  (int) $request->query('per_page', 20),
            );
        } catch (\DomainException $e) {
            return response()->json(['message' => 'Wallet not found'], 404);
        }

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

    public function createAdjustment(CreateAdjustmentRequest $request, string $walletId): JsonResponse
    {
        $adj = $this->adjustmentService->createAdjustment(
            data:     array_merge($request->validated(), ['wallet_id' => $walletId]),
            makerId:  $request->user()->id,
            ip:       $request->ip(),
        );

        return response()->json(['data' => $adj->toArray()], 201);
    }

    public function approveAdjustment(ApproveAdjustmentRequest $request, string $adjustmentId): JsonResponse
    {
        try {
            $adj = $this->adjustmentService->approveAdjustment(
                adjId:     $adjustmentId,
                checkerId: $request->user()->id,
                reason:    $request->validated('reason'),
                ip:        $request->ip(),
            );
        } catch (\DomainException $e) {
            return match ($e->getMessage()) {
                'ADJUSTMENT_NOT_FOUND'  => response()->json(['message' => 'Adjustment not found'], 404),
                'NOT_PENDING_APPROVAL'  => response()->json(['message' => 'Adjustment is not pending approval'], 409),
                'MAKER_CHECKER_VIOLATION' => response()->json(['message' => 'Checker cannot be the same as the maker'], 422),
                default                 => throw $e,
            };
        }

        return response()->json(['data' => $adj->toArray()]);
    }

    public function rejectAdjustment(RejectAdjustmentRequest $request, string $adjustmentId): JsonResponse
    {
        try {
            $adj = $this->adjustmentService->rejectAdjustment(
                adjId:     $adjustmentId,
                checkerId: $request->user()->id,
                reason:    $request->validated('reason'),
                ip:        $request->ip(),
            );
        } catch (\DomainException $e) {
            return match ($e->getMessage()) {
                'ADJUSTMENT_NOT_FOUND' => response()->json(['message' => 'Adjustment not found'], 404),
                'NOT_PENDING_APPROVAL' => response()->json(['message' => 'Adjustment is not pending approval'], 409),
                'MAKER_CHECKER_VIOLATION' => response()->json(['message' => 'Checker cannot be the same as the maker'], 422),
                default                => throw $e,
            };
        }

        return response()->json(['data' => $adj->toArray()]);
    }
}
