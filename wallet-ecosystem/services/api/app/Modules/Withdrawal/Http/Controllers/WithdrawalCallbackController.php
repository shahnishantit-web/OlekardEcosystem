<?php

declare(strict_types=1);

namespace App\Modules\Withdrawal\Http\Controllers;

use App\Modules\Withdrawal\Application\Services\WithdrawalCallbackService;
use App\Modules\Withdrawal\Domain\Exceptions\InvalidCallbackSignatureException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class WithdrawalCallbackController extends Controller
{
    public function __construct(
        private readonly WithdrawalCallbackService $callbackService,
    ) {}

    public function handle(Request $request, string $provider): JsonResponse
    {
        $rawPayload = $request->all();
        $sigHeader  = $request->header('X-Signature') ?? $request->header('X-Callback-Signature');
        $signature  = is_string($sigHeader) ? $sigHeader : '';

        try {
            $this->callbackService->processCallback($provider, $rawPayload, $signature);
        } catch (InvalidCallbackSignatureException) {
            // Still return 200 to prevent provider from retrying with invalid signatures
            return response()->json(['status' => 'acknowledged'], 200);
        }

        return response()->json(['status' => 'acknowledged'], 200);
    }
}
