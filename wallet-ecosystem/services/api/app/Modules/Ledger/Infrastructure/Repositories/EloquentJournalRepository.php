<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Infrastructure\Repositories;

use App\Modules\Ledger\Infrastructure\Models\JournalModel;
use Illuminate\Support\Facades\DB;

class EloquentJournalRepository
{
    public function findById(string $id): ?JournalModel
    {
        return JournalModel::find($id);
    }

    public function findByIdOrFail(string $id): JournalModel
    {
        /** @var JournalModel */
        return JournalModel::findOrFail($id);
    }

    public function findByIdempotencyKey(string $key): ?JournalModel
    {
        return JournalModel::where('idempotency_key', $key)->first();
    }

    public function findByTransactionReference(string $ref, int $page, int $perPage): array
    {
        $offset = ($page - 1) * $perPage;

        $total = DB::connection('platform')
            ->table('ledger.journals')
            ->where('transaction_reference', $ref)
            ->count();

        $rows = DB::connection('platform')
            ->table('ledger.journals')
            ->where('transaction_reference', $ref)
            ->orderBy('posted_at', 'desc')
            ->limit($perPage)
            ->offset($offset)
            ->get();

        return [
            'data' => $rows->map(fn ($r) => JournalModel::find($r->id))->filter()->values()->all(),
            'meta' => [
                'total'    => $total,
                'page'     => $page,
                'per_page' => $perPage,
                'pages'    => (int) ceil($total / $perPage),
            ],
        ];
    }

    public function create(array $attributes): JournalModel
    {
        return JournalModel::create($attributes);
    }
}
