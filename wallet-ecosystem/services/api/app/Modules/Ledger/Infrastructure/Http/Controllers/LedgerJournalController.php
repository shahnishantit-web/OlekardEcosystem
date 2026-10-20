<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Infrastructure\Http\Controllers;

use App\Modules\Ledger\Infrastructure\Repositories\EloquentJournalRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class LedgerJournalController
{
    public function __construct(
        private readonly EloquentJournalRepository $journalRepository,
    ) {}

    public function show(Request $request, string $id): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());

        $journal = $this->journalRepository->findById($id);

        if ($journal === null) {
            return $this->error('JOURNAL_NOT_FOUND', 'Journal not found.', 404, $correlationId);
        }

        return $this->success($this->formatJournal($journal, withLines: true), 200, $correlationId);
    }

    public function index(Request $request): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Str::uuid());
        $txnRef         = (string) ($request->query('transaction_reference', '') ?? '');
        $page           = max(1, (int) $request->query('page', 1));
        $perPage        = min(100, max(1, (int) $request->query('per_page', 20)));

        if ($txnRef === '') {
            return response()->json([
                'success'        => true,
                'data'           => [],
                'meta'           => ['total' => 0, 'page' => $page, 'per_page' => $perPage, 'pages' => 0],
                'error'          => null,
                'correlation_id' => $correlationId,
            ], 200, ['X-Correlation-ID' => $correlationId]);
        }

        $result = $this->journalRepository->findByTransactionReference($txnRef, $page, $perPage);

        $data = array_map(
            fn ($journal) => $this->formatJournal($journal, withLines: true),
            $result['data'],
        );

        return response()->json([
            'success'        => true,
            'data'           => $data,
            'meta'           => $result['meta'],
            'error'          => null,
            'correlation_id' => $correlationId,
        ], 200, ['X-Correlation-ID' => $correlationId]);
    }

    private function formatJournal(object $journal, bool $withLines): array
    {
        $entryType = $journal->entry_type instanceof \BackedEnum
            ? $journal->entry_type->value : $journal->entry_type;
        $status = $journal->status instanceof \BackedEnum
            ? $journal->status->value : $journal->status;

        $data = [
            'id'                    => $journal->id,
            'entry_type'            => $entryType,
            'status'                => $status,
            'currency_code'         => $journal->currency_code,
            'transaction_reference' => $journal->transaction_reference,
            'correlation_id'        => $journal->correlation_id,
            'reversed_journal_id'   => $journal->reversed_journal_id ?? null,
            'posted_at'             => $journal->posted_at?->toIso8601String(),
        ];

        if ($withLines) {
            $data['lines'] = $journal->lines->map(fn ($line) => [
                'id'                       => $line->id,
                'ledger_account_id'        => $line->ledger_account_id,
                'direction'                => $line->direction instanceof \BackedEnum
                    ? $line->direction->value : $line->direction,
                'amount'                   => (int) $line->amount,
                'currency_code'            => $line->currency_code,
            ])->all();
        }

        return $data;
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
