<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Infrastructure\Http\Controllers;

use App\Modules\Wallet\Infrastructure\Http\Resources\WalletStatusHistoryResource;
use App\Modules\Wallet\Infrastructure\Repositories\EloquentWalletRepository;
use App\Modules\Wallet\Infrastructure\Repositories\WalletStatusHistoryRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class WalletStatusHistoryController extends Controller
{
    public function __construct(
        private readonly WalletStatusHistoryRepository $historyRepository,
        private readonly EloquentWalletRepository      $walletRepository,
    ) {}

    public function index(Request $request, string $id): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $callerId      = $request->attributes->get('identity.user_id');
        $permissions   = (array) $request->attributes->get('identity.permissions', []);
        $canReadAny    = in_array('wallet.read.any', $permissions, true);

        $wallet = $this->walletRepository->findById($id);

        if ($wallet === null || (!$canReadAny && $wallet->owner_id !== $callerId)) {
            return $this->error('WALLET_NOT_FOUND', 'Wallet not found.', 404, $correlationId);
        }

        $cursor  = $request->query('cursor') ?: null;
        $perPage = min((int) $request->query('per_page', 20), 100);
        $result  = $this->historyRepository->paginateByWalletId($id, $cursor, $perPage);

        return response()->json([
            'success'        => true,
            'data'           => [
                'history'      => array_map(fn ($h) => (new WalletStatusHistoryResource($h))->toArray($request), $result['items']),
                'next_cursor'  => $result['next_cursor'],
                'has_more'     => $result['has_more'],
            ],
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
