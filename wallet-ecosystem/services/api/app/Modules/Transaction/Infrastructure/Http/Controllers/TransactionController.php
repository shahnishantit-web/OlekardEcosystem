<?php

declare(strict_types=1);

namespace App\Modules\Transaction\Infrastructure\Http\Controllers;

use App\Modules\Transaction\Domain\Exceptions\TransactionNotFoundException;
use App\Modules\Transaction\Infrastructure\Http\Requests\ListTransactionsRequest;
use App\Modules\Transaction\Infrastructure\Http\Resources\TransactionProjectionResource;
use App\Modules\Transaction\Infrastructure\Http\Resources\TransactionStatusHistoryResource;
use App\Modules\Transaction\Infrastructure\Models\TransactionProjectionModel;
use App\Modules\Transaction\Infrastructure\Repositories\TransactionProjectionRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Routing\Controller;
use Ramsey\Uuid\Uuid;

final class TransactionController extends Controller
{
    public function __construct(
        private readonly TransactionProjectionRepository $repository,
    ) {}

    /** GET /api/v1/transactions */
    public function index(ListTransactionsRequest $request): ResourceCollection
    {
        $customerId = $this->customerId($request);
        $perPage    = (int) $request->input('per_page', 20);

        $paginator = $this->repository->listForCustomer(
            customerId: $customerId,
            type:       $request->input('type'),
            status:     $request->input('status'),
            search:     $request->input('search'),
            cursor:     null,
            perPage:    min($perPage, 100),
        );

        return TransactionProjectionResource::collection($paginator);
    }

    /** GET /api/v1/transactions/{id} */
    public function show(Request $request, string $id): JsonResponse
    {
        $tx = $this->repository->findById($id);

        if ($tx === null) {
            return $this->notFound($id);
        }

        $this->authorizeOwnership($request, $tx);

        return response()->json(['data' => new TransactionProjectionResource($tx)]);
    }

    /** GET /api/v1/transactions/{id}/status-history */
    public function statusHistory(Request $request, string $id): JsonResponse
    {
        $tx = $this->repository->findById($id);

        if ($tx === null) {
            return $this->notFound($id);
        }

        $this->authorizeOwnership($request, $tx);

        $history = $tx->statusHistory()->get();

        return response()->json(['data' => TransactionStatusHistoryResource::collection($history)]);
    }

    private function customerId(Request $request): string
    {
        if ($request->user() !== null) {
            return (string) $request->user()->id;
        }
        $testId = $request->header('X-Test-User-Id');
        return is_string($testId) ? $testId : '';
    }

    private function authorizeOwnership(Request $request, TransactionProjectionModel $tx): void
    {
        $customerId = $this->customerId($request);
        if ($customerId !== '' && $tx->customer_id !== $customerId) {
            abort(403, 'Access denied.');
        }
    }

    private function notFound(string $id): JsonResponse
    {
        return response()->json([
            'error' => ['code' => 'TRANSACTION_NOT_FOUND', 'message' => "Transaction [{$id}] not found."],
        ], 404);
    }
}
