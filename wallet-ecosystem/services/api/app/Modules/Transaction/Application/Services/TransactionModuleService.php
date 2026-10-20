<?php

declare(strict_types=1);

namespace App\Modules\Transaction\Application\Services;

use App\Modules\Transaction\Domain\Contracts\TransactionModuleContract;
use App\Modules\Transaction\Infrastructure\Models\TransactionProjectionModel;

final class TransactionModuleService implements TransactionModuleContract
{
    public function generateReference(): string
    {
        return 'TXN-' . strtoupper(date('Ymd')) . '-' . strtoupper(bin2hex(random_bytes(4)));
    }

    public function status(string $transactionReference): ?string
    {
        return TransactionProjectionModel::where('reference', $transactionReference)->value('status');
    }

    public function listForCompliance(array $filters, ?string $cursor, int $perPage): array
    {
        $query = TransactionProjectionModel::query()->orderByDesc('created_at')->orderByDesc('id');
        foreach (['user_id' => 'customer_id', 'transaction_type' => 'type', 'outcome' => 'status'] as $filter => $column) {
            if (! empty($filters[$filter])) $query->where($column, $filters[$filter]);
        }
        if (! empty($filters['from'])) $query->where('created_at', '>=', $filters['from']);
        if (! empty($filters['to'])) $query->where('created_at', '<=', $filters['to']);
        $page = $query->cursorPaginate(max(1, min($perPage, 100)), ['*'], 'cursor', $cursor);
        return [
            'data' => $page->getCollection()->map(fn ($row): array => [
                'transaction_id' => $row->id, 'transaction_type' => $row->type->value,
                'amount_minor' => (int) $row->amount, 'currency_code' => $row->currency_code,
                'outcome' => $row->status, 'occurred_at' => $row->created_at?->toIso8601String(),
                'user_id' => $row->customer_id,
            ])->all(),
            'next_cursor' => $page->nextCursor()?->encode(),
        ];
    }
}
