<?php

declare(strict_types=1);

namespace App\Modules\Support\Domain\Contracts;

interface SupportModuleContract
{
    public function hasActiveDispute(string $transactionId): bool;

    public function getDisputeReferenceForTransaction(string $transactionId): ?string;

    public function getTicketReferenceForDispute(string $disputeId): ?string;
}
