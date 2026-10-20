<?php

declare(strict_types=1);

namespace App\Modules\AgentFloat\Application\Services;

use App\Modules\AgentFloat\Infrastructure\Models\FloatTransactionModel;

final class FloatTransactionService
{
    public function list(
        string  $agentId,
        int     $page,
        int     $perPage,
        ?string $from,
        ?string $to,
        ?string $type,
    ): array {
        $query = FloatTransactionModel::where('agent_id', $agentId)
            ->orderBy('created_at', 'desc');

        if ($from !== null) {
            $query->whereDate('created_at', '>=', $from);
        }

        if ($to !== null) {
            $query->whereDate('created_at', '<=', $to);
        }

        if ($type !== null) {
            $query->where('type', $type);
        }

        $total   = $query->count();
        $records = $query->forPage($page, $perPage)->get();
        $pages   = (int) ceil($total / $perPage);

        return [
            'data' => $records,
            'meta' => [
                'total'    => $total,
                'page'     => $page,
                'per_page' => $perPage,
                'pages'    => max(1, $pages),
            ],
        ];
    }
}
