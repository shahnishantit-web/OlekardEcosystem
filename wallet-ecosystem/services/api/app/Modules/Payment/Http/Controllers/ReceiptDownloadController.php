<?php

declare(strict_types=1);

namespace App\Modules\Payment\Http\Controllers;

use App\Modules\Payment\Application\Services\ReceiptDownloadTokenService;
use App\Modules\Payment\Application\Services\ReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Unauthenticated receipt download endpoint.
 *
 * The token IS the credential — no auth header required.
 * Single-use enforcement and expiry validation are in ReceiptDownloadTokenService::redeem().
 */
final class ReceiptDownloadController extends Controller
{
    public function __construct(
        private readonly ReceiptDownloadTokenService $tokenService,
        private readonly ReceiptService              $receiptService,
    ) {
    }

    public function download(Request $request, string $token): JsonResponse
    {
        $tokenModel = $this->tokenService->redeem($token);

        $receipt = $this->receiptService->compileForAdmin($tokenModel->payment_intent_id);

        return response()->json(['data' => $receipt]);
    }
}
