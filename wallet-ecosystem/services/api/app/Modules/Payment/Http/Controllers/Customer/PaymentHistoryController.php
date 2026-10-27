<?php

declare(strict_types=1);

namespace App\Modules\Payment\Http\Controllers\Customer;

use App\Modules\Payment\Application\Services\PaymentHistoryService;
use App\Modules\Payment\Http\Requests\GetPaymentHistoryRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

final class PaymentHistoryController extends Controller
{
    public function __construct(
        private readonly PaymentHistoryService $historyService,
    ) {
    }

    public function index(GetPaymentHistoryRequest $request): JsonResponse
    {
        /** @var \App\Modules\Identity\Infrastructure\Models\UserModel $user */
        $user = $request->user();

        $paginator = $this->historyService->getForPayer(
            payerActorId: $user->id,
            filters:      $request->validated(),
        );

        return response()->json([
            'data' => collect($paginator->items())->filter()->values(),
            'meta' => [
                'current_page'  => $paginator->currentPage(),
                'per_page'      => $paginator->perPage(),
                'total'         => $paginator->total(),
                'last_page'     => $paginator->lastPage(),
            ],
        ]);
    }
}
