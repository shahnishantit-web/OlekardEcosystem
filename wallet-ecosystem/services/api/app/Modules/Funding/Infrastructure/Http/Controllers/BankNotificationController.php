<?php

declare(strict_types=1);

namespace App\Modules\Funding\Infrastructure\Http\Controllers;

use App\Modules\Funding\Application\Services\BankNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class BankNotificationController extends Controller
{
    public function __construct(
        private readonly BankNotificationService $bankNotificationService,
    ) {}

    /**
     * POST /api/v1/funding/bank/notifications
     *
     * System-to-system endpoint. Authenticated via `funding.bank-api-key` middleware.
     * Idempotent: duplicate bank_notification_id returns 200 silently.
     */
    public function handle(Request $request): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-Id') ?? (string) \Ramsey\Uuid\Uuid::uuid7();

        $request->validate([
            'bank_notification_id' => ['required', 'string', 'max:100'],
            'bank_reference_code'  => ['required', 'string', 'max:50'],
            'amount'               => ['required', 'integer', 'min:1'],
            'currency_code'        => ['required', 'string', 'size:3'],
        ]);

        $this->bankNotificationService->handle([
            'bank_notification_id' => $request->input('bank_notification_id'),
            'bank_reference_code'  => strtoupper($request->input('bank_reference_code')),
            'amount'               => (int) $request->input('amount'),
            'currency_code'        => strtoupper($request->input('currency_code')),
            'correlation_id'       => $correlationId,
            'raw_payload'          => $request->all(),
        ]);

        return response()->json(['acknowledged' => true]);
    }
}
