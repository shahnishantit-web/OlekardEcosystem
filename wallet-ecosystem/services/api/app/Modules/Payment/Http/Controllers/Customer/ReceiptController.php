<?php

declare(strict_types=1);

namespace App\Modules\Payment\Http\Controllers\Customer;

use App\Modules\Payment\Application\Services\ReceiptDownloadTokenService;
use App\Modules\Payment\Application\Services\ReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class ReceiptController extends Controller
{
    public function __construct(
        private readonly ReceiptService              $receiptService,
        private readonly ReceiptDownloadTokenService $tokenService,
    ) {
    }

    public function show(Request $request, string $intentId): JsonResponse
    {
        /** @var \App\Modules\Identity\Infrastructure\Models\UserModel $user */
        $user = $request->user();

        $receipt = $this->receiptService->compile($intentId, $user->id);

        return response()->json(['data' => $receipt]);
    }

    public function issueDownloadToken(Request $request, string $intentId): JsonResponse
    {
        /** @var \App\Modules\Identity\Infrastructure\Models\UserModel $user */
        $user = $request->user();

        $token = $this->tokenService->issue($intentId, $user->id);

        $downloadUrl = url("/api/v1/payment/receipts/download/{$token->token}");

        return response()->json([
            'data' => [
                'token'        => $token->token,
                'expires_at'   => $token->expires_at->toIso8601String(),
                'download_url' => $downloadUrl,
            ],
        ], 201);
    }
}
