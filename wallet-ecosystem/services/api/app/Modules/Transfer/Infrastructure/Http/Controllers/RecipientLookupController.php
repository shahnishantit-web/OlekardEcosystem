<?php

declare(strict_types=1);

namespace App\Modules\Transfer\Infrastructure\Http\Controllers;

use App\Modules\Transfer\Application\Services\RecipientLookupService;
use App\Modules\Transfer\Domain\Exceptions\RecipientNotFoundException;
use App\Modules\Transfer\Domain\Exceptions\RecipientWalletInactiveException;
use App\Modules\Transfer\Domain\Exceptions\SelfTransferException;
use App\Modules\Transfer\Infrastructure\Http\Requests\RecipientLookupRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Ramsey\Uuid\Uuid;

final class RecipientLookupController extends Controller
{
    public function __construct(private readonly RecipientLookupService $service) {}

    public function lookup(RecipientLookupRequest $request): JsonResponse
    {
        $senderCustomerId = $request->user()?->id ?? $request->header('X-Test-User-Id');

        $correlationId = $request->header('X-Correlation-Id', (string) Uuid::uuid7());

        try {
            $result = $this->service->lookup(
                senderCustomerId: $senderCustomerId,
                phoneNumber:      $request->input('phone_number'),
                walletId:         $request->input('wallet_number'),
                currencyCode:     $request->input('currency_code', 'BWP'),
            );
        } catch (RecipientNotFoundException $e) {
            return response()->json([
                'error' => ['code' => 'RECIPIENT_NOT_FOUND', 'message' => $e->getMessage()],
            ], 404);
        } catch (RecipientWalletInactiveException $e) {
            return response()->json([
                'error' => ['code' => 'RECIPIENT_WALLET_INACTIVE', 'message' => $e->getMessage()],
            ], 422);
        } catch (SelfTransferException $e) {
            return response()->json([
                'error' => ['code' => 'SELF_TRANSFER_NOT_ALLOWED', 'message' => $e->getMessage()],
            ], 422);
        }

        return response()->json([
            'data' => [
                'lookup_token' => $result['lookup_token'],
                'display_name' => $result['display_name'],
                'masked_mobile' => $result['masked_mobile'],
            ],
        ]);
    }
}
