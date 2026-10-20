<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Infrastructure\Http\Controllers;

use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;
use App\Modules\Wallet\Application\Services\WalletBalanceService;
use App\Modules\Wallet\Application\Services\WalletPocketService;
use App\Modules\Wallet\Domain\Exceptions\PocketAlreadyExistsException;
use App\Modules\Wallet\Domain\Exceptions\UnsupportedCurrencyException;
use App\Modules\Wallet\Domain\Exceptions\WalletNotFoundException;
use App\Modules\Wallet\Infrastructure\Http\Requests\AddPocketRequest;
use App\Modules\Wallet\Infrastructure\Http\Resources\BalanceResource;
use App\Modules\Wallet\Infrastructure\Http\Resources\PocketResource;
use App\Modules\Wallet\Infrastructure\Repositories\EloquentCurrencyPocketRepository;
use App\Modules\Wallet\Infrastructure\Repositories\EloquentWalletRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class WalletPocketController extends Controller
{
    public function __construct(
        private readonly WalletPocketService              $pocketService,
        private readonly WalletBalanceService             $balanceService,
        private readonly EloquentCurrencyPocketRepository $pocketRepository,
        private readonly EloquentWalletRepository         $walletRepository,
        private readonly MetricsCollectorInterface        $metrics,
    ) {}

    public function index(Request $request, string $id): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $callerId      = $request->attributes->get('identity.user_id');

        $wallet = $this->walletRepository->findById($id);
        if ($wallet === null || $wallet->owner_id !== $callerId) {
            return $this->error('WALLET_NOT_FOUND', 'Wallet not found.', 404, $correlationId);
        }

        $pockets = $this->pocketRepository->findAllByWalletId($id);

        return response()->json([
            'success'        => true,
            'data'           => ['pockets' => array_map(fn ($p) => (new PocketResource($p))->toArray($request), $pockets)],
            'error'          => null,
            'correlation_id' => $correlationId,
        ], 200, ['X-Correlation-ID' => $correlationId]);
    }

    public function store(AddPocketRequest $request, string $id): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Str::uuid());
        $idempotencyKey = $request->header('Idempotency-Key');
        $actorId        = $request->attributes->get('identity.user_id');

        if (!$idempotencyKey) {
            return $this->error('VALIDATION_ERROR', 'Idempotency-Key header is required.', 422, $correlationId);
        }

        try {
            $pocket = $this->pocketService->addPocket(
                walletId:      $id,
                currencyCode:  $request->validated('currency_code'),
                actorId:       $actorId,
                correlationId: $correlationId,
            );

            return response()->json([
                'success'        => true,
                'data'           => (new PocketResource($pocket))->toArray($request),
                'error'          => null,
                'correlation_id' => $correlationId,
            ], 201, ['X-Correlation-ID' => $correlationId]);
        } catch (PocketAlreadyExistsException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->httpStatus, $correlationId);
        } catch (UnsupportedCurrencyException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->httpStatus, $correlationId);
        } catch (WalletNotFoundException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->httpStatus, $correlationId);
        }
    }

    public function balance(Request $request, string $id, string $code): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $callerId      = $request->attributes->get('identity.user_id');
        $hasReadAny    = (array) $request->attributes->get('identity.permissions', []);

        // IDOR check
        $wallet = $this->walletRepository->findById($id);
        $canReadAny = in_array('wallet.read.any', $hasReadAny, true);

        if ($wallet === null || (!$canReadAny && $wallet->owner_id !== $callerId)) {
            $this->metrics->increment('wallet.idor.blocked', ['endpoint' => 'balance']);
            return $this->error('WALLET_NOT_FOUND', 'Wallet not found.', 404, $correlationId);
        }

        $start = microtime(true);

        try {
            $balance = $this->balanceService->getBalance($id, $code, $correlationId);
        } catch (WalletNotFoundException $e) {
            $this->metrics->histogram('wallet.balance.query.duration', microtime(true) - $start);
            return $this->error($e->errorCode, $e->getMessage(), $e->httpStatus, $correlationId);
        } catch (\RuntimeException $e) {
            $this->metrics->histogram('wallet.balance.query.duration', microtime(true) - $start);
            if ($e->getCode() === 503) {
                return $this->error('LEDGER_UNAVAILABLE', 'Ledger service is unavailable.', 503, $correlationId);
            }
            throw $e;
        } finally {
            $this->metrics->histogram('wallet.balance.query.duration', microtime(true) - $start);
        }

        return response()->json([
            'success'        => true,
            'data'           => (new BalanceResource($balance))->toArray($request),
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
