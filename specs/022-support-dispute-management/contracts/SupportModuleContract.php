<?php

declare(strict_types=1);

namespace App\Modules\Support\Domain\Contracts;

/**
 * SupportModuleContract — the cross-module interface for support and dispute queries.
 *
 * Other modules (E11 Transactions, E23 Audit) that need to check dispute status
 * against a transaction MUST use this interface only. Direct access to
 * Support Eloquent models from outside App\Modules\Support\ is prohibited.
 */
interface SupportModuleContract
{
    /**
     * Returns true if an active (non-terminal) dispute exists for the given transaction ID.
     *
     * Active statuses: raised, evidence_submitted, under_investigation, pending_confirmation, approved.
     * Terminal statuses (not active): rejected, refunded.
     */
    public function hasActiveDispute(string $transactionId): bool;

    /**
     * Returns the dispute reference (e.g. DSP-000001) for a transaction, or null if none.
     */
    public function getDisputeReferenceForTransaction(string $transactionId): ?string;

    /**
     * Returns the support ticket reference (e.g. TKT-000001) for a dispute ID, or null if unlinked.
     */
    public function getTicketReferenceForDispute(string $disputeId): ?string;
}
