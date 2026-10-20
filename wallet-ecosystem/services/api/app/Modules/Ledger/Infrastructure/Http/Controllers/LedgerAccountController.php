<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Infrastructure\Http\Controllers;

use App\Modules\Ledger\Application\Services\LedgerAccountService;
use App\Modules\Ledger\Application\Services\LedgerBalanceService;
use App\Modules\Ledger\Infrastructure\Http\Requests\RegisterAccountRequest;
use App\Modules\Platform\Domain\Contracts\IdempotencyServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class LedgerAccountController
{
    public function __construct(
        private readonly LedgerBalanceService $balanceService,
        private readonly LedgerAccountService $accountService,
        private readonly IdempotencyServiceInterface $idempotencyService,
    ) {}

    public function balance(Request $request, string $reference): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());

        $account = DB::connection('platform')
            ->table('ledger.ledger_accounts')
            ->where('ledger_account_reference', $reference)
            ->first(['id', 'currency_code', 'normal_balance', 'owner_reference']);

        if ($account === null) {
            // Unknown reference: return zero balance (FR-E07-022)
            return $this->success([
                'ledger_account_reference' => $reference,
                'currency_code'            => null,
                'balance'                  => 0,
                'normal_balance'           => null,
                'computed_at'              => now()->toIso8601String(),
            ], 200, $correlationId);
        }

        // IDOR check: unless actor has ledger.read.any, verify owner_reference matches actor
        $actorPermissions = $request->attributes->get('identity.permissions', []);
        $actorId          = (string) $request->attributes->get('identity.user_id', '');

        if (!in_array('ledger.read.any', $actorPermissions, true)) {
            if ($account->owner_reference !== null && $account->owner_reference !== $actorId) {
                return $this->notFound($correlationId);
            }
        }

        $balance = $this->balanceService->getBalance($reference);

        return $this->success([
            'ledger_account_reference' => $reference,
            'currency_code'            => $account->currency_code,
            'balance'                  => $balance,
            'normal_balance'           => $account->normal_balance,
            'computed_at'              => now()->toIso8601String(),
        ], 200, $correlationId);
    }

    public function entries(Request $request, string $reference): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $page          = max(1, (int) $request->query('page', 1));
        $perPage       = min(100, max(1, (int) $request->query('per_page', 20)));

        $account = DB::connection('platform')
            ->table('ledger.ledger_accounts')
            ->where('ledger_account_reference', $reference)
            ->first(['id', 'owner_reference']);

        if ($account === null) {
            return response()->json([
                'success'        => true,
                'data'           => [],
                'meta'           => ['total' => 0, 'page' => $page, 'per_page' => $perPage, 'pages' => 0],
                'error'          => null,
                'correlation_id' => $correlationId,
            ], 200, ['X-Correlation-ID' => $correlationId]);
        }

        // IDOR check
        $actorPermissions = $request->attributes->get('identity.permissions', []);
        $actorId          = (string) $request->attributes->get('identity.user_id', '');

        if (!in_array('ledger.read.any', $actorPermissions, true)) {
            if ($account->owner_reference !== null && $account->owner_reference !== $actorId) {
                return $this->notFound($correlationId);
            }
        }

        $history = $this->balanceService->getAccountHistory($reference, $page, $perPage);

        return response()->json([
            'success'        => true,
            'data'           => $history['data'],
            'meta'           => $history['meta'],
            'error'          => null,
            'correlation_id' => $correlationId,
        ], 200, ['X-Correlation-ID' => $correlationId]);
    }

    public function register(RegisterAccountRequest $request): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Str::uuid());
        $idempotencyKey = $request->header('Idempotency-Key');
        $actorId        = $request->attributes->get('identity.user_id', '');

        if (!$idempotencyKey) {
            return $this->error('MISSING_IDEMPOTENCY_KEY', 'Idempotency-Key header is required.', 422, $correlationId);
        }

        $cached   = $this->idempotencyService->findCompletedResponse($idempotencyKey, $actorId);
        $isReplay = $cached !== null;

        try {
            $account = $this->accountService->registerAccount(
                ref:           $request->validated('ledger_account_reference'),
                accountType:   $request->validated('account_type'),
                currencyCode:  $request->validated('currency_code'),
                ownerRef:      $request->validated('owner_reference'),
                ownerType:     $request->validated('owner_type'),
                actorId:       $actorId,
                correlationId: $correlationId,
            );
        } catch (\RuntimeException $e) {
            return $this->error('ACCOUNT_CONFLICT', $e->getMessage(), 409, $correlationId);
        }

        $status = $isReplay ? 200 : 201;

        return $this->success([
            'id'                       => $account->id,
            'ledger_account_reference' => $account->ledger_account_reference,
            'account_type'             => $account->account_type instanceof \BackedEnum
                ? $account->account_type->value : $account->account_type,
            'currency_code'            => $account->currency_code,
            'status'                   => $account->status instanceof \BackedEnum
                ? $account->status->value : $account->status,
        ], $status, $correlationId);
    }

    private function success(array $data, int $status, string $correlationId): JsonResponse
    {
        return response()->json(
            ['success' => true, 'data' => $data, 'error' => null, 'correlation_id' => $correlationId],
            $status,
            ['X-Correlation-ID' => $correlationId],
        );
    }

    private function error(string $code, string $message, int $status, string $correlationId): JsonResponse
    {
        return response()->json(
            ['success' => false, 'data' => null, 'error' => ['code' => $code, 'message' => $message], 'correlation_id' => $correlationId],
            $status,
            ['X-Correlation-ID' => $correlationId],
        );
    }

    private function notFound(string $correlationId): JsonResponse
    {
        return $this->error('NOT_FOUND', 'Ledger account not found.', 404, $correlationId);
    }
}
