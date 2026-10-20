<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Infrastructure\Http\Controllers;

use App\Modules\Wallet\Application\Services\WalletLimitService;
use App\Modules\Wallet\Domain\Exceptions\WalletNotFoundException;
use App\Modules\Wallet\Infrastructure\Http\Requests\UpdateLimitsRequest;
use App\Modules\Wallet\Infrastructure\Http\Resources\LimitResource;
use App\Modules\Wallet\Infrastructure\Repositories\EloquentWalletRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class WalletLimitController extends Controller
{
    public function __construct(
        private readonly WalletLimitService       $limitService,
        private readonly EloquentWalletRepository $walletRepository,
    ) {}

    public function index(Request $request, string $id): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());

        $wallet = $this->walletRepository->findById($id);
        if ($wallet === null) {
            return $this->error('WALLET_NOT_FOUND', 'Wallet not found.', 404, $correlationId);
        }

        $limits = $this->limitService->getLimitsForWallet($id);

        return response()->json([
            'success'        => true,
            'data'           => ['limits' => array_map(fn ($l) => (new LimitResource($l))->toArray($request), $limits)],
            'error'          => null,
            'correlation_id' => $correlationId,
        ], 200, ['X-Correlation-ID' => $correlationId]);
    }

    public function update(UpdateLimitsRequest $request, string $id): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $actorId       = $request->attributes->get('identity.user_id');

        try {
            $this->limitService->updateLimits(
                walletId:       $id,
                limitUpdates:   $request->validated('limits'),
                actorId:        $actorId,
                correlationId:  $correlationId,
            );
        } catch (WalletNotFoundException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->httpStatus, $correlationId);
        }

        $limits = $this->limitService->getLimitsForWallet($id);

        return response()->json([
            'success'        => true,
            'data'           => ['limits' => array_map(fn ($l) => (new LimitResource($l))->toArray($request), $limits)],
            'error'          => null,
            'correlation_id' => $correlationId,
        ], 200, ['X-Correlation-ID' => $correlationId]);
    }

    private function error(string $code, string $message, int $status, string $correlationId): JsonResponse
    {
        return response()->json([
            'success'        => false,
            'data'           => null,
            'error'          => ['code' => $code, 'message' => $message, 'details' => []],
            'correlation_id' => $correlationId,
        ], $status, ['X-Correlation-ID' => $correlationId]);
    }
}
