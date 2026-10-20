<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Infrastructure\Http\Controllers;

use App\Modules\Ledger\Application\Services\LedgerPostingService;
use App\Modules\Ledger\Application\Services\LedgerReverseService;
use App\Modules\Ledger\Domain\Exceptions\AccountClosedException;
use App\Modules\Ledger\Domain\Exceptions\CurrencyMismatchException;
use App\Modules\Ledger\Domain\Exceptions\InvalidAmountException;
use App\Modules\Ledger\Domain\Exceptions\JournalAlreadyReversedException;
use App\Modules\Ledger\Domain\Exceptions\JournalNotFoundException;
use App\Modules\Ledger\Domain\Exceptions\UnbalancedJournalException;
use App\Modules\Ledger\Infrastructure\Http\Requests\PostEntryRequest;
use App\Modules\Ledger\Infrastructure\Http\Requests\ReverseJournalRequest;
use App\Modules\Platform\Domain\Contracts\IdempotencyServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

final class LedgerEntryController
{
    public function __construct(
        private readonly LedgerPostingService $postingService,
        private readonly LedgerReverseService $reverseService,
        private readonly IdempotencyServiceInterface $idempotencyService,
    ) {}

    public function debit(PostEntryRequest $request): JsonResponse
    {
        return $this->handlePost($request, 'debit');
    }

    public function credit(PostEntryRequest $request): JsonResponse
    {
        return $this->handlePost($request, 'credit');
    }

    public function fee(PostEntryRequest $request): JsonResponse
    {
        return $this->handlePost($request, 'fee');
    }

    public function revenue(PostEntryRequest $request): JsonResponse
    {
        return $this->handlePost($request, 'revenue');
    }

    public function commission(PostEntryRequest $request): JsonResponse
    {
        return $this->handlePost($request, 'commission');
    }

    public function reverse(ReverseJournalRequest $request, string $id): JsonResponse
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
            $reversalId = $this->reverseService->reverse(
                journalId:      $id,
                actorId:        $actorId,
                idempotencyKey: $idempotencyKey,
                correlationId:  $request->validated('correlation_id') ?: $correlationId,
                description:    $request->validated('description'),
            );
        } catch (JournalNotFoundException $e) {
            return $this->error('JOURNAL_NOT_FOUND', $e->getMessage(), 404, $correlationId);
        } catch (JournalAlreadyReversedException $e) {
            return $this->error('JOURNAL_ALREADY_REVERSED', $e->getMessage(), 422, $correlationId);
        }

        $status = $isReplay ? 200 : 201;

        return $this->success([
            'reversal_journal_id' => $reversalId,
            'reversed_journal_id' => $id,
            'status'              => 'posted',
            'posted_at'           => now()->toIso8601String(),
        ], $status, $correlationId);
    }

    private function handlePost(PostEntryRequest $request, string $entryType): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Str::uuid());
        $idempotencyKey = $request->header('Idempotency-Key');
        $actorId        = $request->attributes->get('identity.user_id', '');

        if (!$idempotencyKey) {
            return $this->error('MISSING_IDEMPOTENCY_KEY', 'Idempotency-Key header is required.', 422, $correlationId);
        }

        // Detect replay before posting; 200 if already completed, 201 if new
        $cached   = $this->idempotencyService->findCompletedResponse($idempotencyKey, $actorId);
        $isReplay = $cached !== null;

        $payload = array_merge($request->validated(), [
            'idempotency_key' => $idempotencyKey,
            'correlation_id'  => $request->validated('correlation_id') ?: $correlationId,
        ]);

        try {
            $journalId = $this->postingService->post($payload, $actorId, $entryType);
        } catch (UnbalancedJournalException $e) {
            return $this->error('UNBALANCED_JOURNAL', $e->getMessage(), 422, $correlationId);
        } catch (CurrencyMismatchException $e) {
            return $this->error('CURRENCY_MISMATCH', $e->getMessage(), 422, $correlationId);
        } catch (InvalidAmountException $e) {
            return $this->error('INVALID_AMOUNT', $e->getMessage(), 422, $correlationId);
        } catch (AccountClosedException $e) {
            return $this->error('ACCOUNT_CLOSED', $e->getMessage(), 422, $correlationId);
        }

        $status = $isReplay ? 200 : 201;

        $data = [
            'journal_id'            => $journalId,
            'entry_type'            => $entryType,
            'currency_code'         => $payload['currency_code'],
            'status'                => 'posted',
            'transaction_reference' => $payload['transaction_reference'] ?? null,
            'posted_at'             => now()->toIso8601String(),
        ];

        return $this->success($data, $status, $correlationId);
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
}
