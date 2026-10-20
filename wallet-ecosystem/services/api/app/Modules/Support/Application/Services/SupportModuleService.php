<?php

declare(strict_types=1);

namespace App\Modules\Support\Application\Services;

use App\Modules\Support\Domain\Contracts\SupportModuleContract;
use App\Modules\Support\Infrastructure\Models\DisputeModel;
use App\Modules\Support\Infrastructure\Models\SupportTicketModel;

final class SupportModuleService implements SupportModuleContract
{
    public function hasActiveDispute(string $transactionId): bool
    {
        return DisputeModel::query()->activeForTransaction($transactionId)->exists();
    }

    public function getDisputeReferenceForTransaction(string $transactionId): ?string
    {
        return DisputeModel::query()
            ->activeForTransaction($transactionId)
            ->value('dispute_number');
    }

    public function getTicketReferenceForDispute(string $disputeId): ?string
    {
        $dispute = DisputeModel::find($disputeId);

        if ($dispute === null) {
            return null;
        }

        return SupportTicketModel::where('id', $dispute->ticket_id)->value('ticket_number');
    }
}
