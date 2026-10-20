<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Contracts\Providers;

use App\Modules\Platform\Domain\Enums\ReconciliationState;

/**
 * Exposes the reconciliation state machine for ExternalReferenceMapping records.
 *
 * The UNMATCHED state is reserved in ReconciliationState for future reconciliation
 * epics (planned: E20). No method in this contract transitions a record to UNMATCHED.
 * Implementations MUST NOT silently transition records to UNMATCHED. The
 * PENDING→UNMATCHED and UNMATCHED→EXCEPTION transitions are deferred to E20.
 */
interface ProviderReconciliationContract
{
    /**
     * Return the current reconciliation state for the given external reference.
     *
     * @throws \DomainException if no record exists for (providerKey, providerReference).
     */
    public function getState(string $providerKey, string $providerReference): ReconciliationState;

    /**
     * Transition the external reference record to MATCHED state.
     *
     * Valid source states: PENDING, EXCEPTION (when reconciliation_reason and
     * reconciliation_owner are already set on the record).
     * MATCHED is terminal within E1000 — no further E1000 transitions are defined from MATCHED.
     *
     * @throws \DomainException if the record is already in MATCHED state.
     * @throws \DomainException if the source state is EXCEPTION and reconciliation_reason
     *                          or reconciliation_owner is null on the record.
     */
    public function match(string $providerKey, string $providerReference): void;

    /**
     * Transition the external reference record to EXCEPTION state.
     *
     * Requires reason, owner, and timestamp — no-silent-resolution rule (OKI-ARCH-2026-003 §10).
     * Valid source states: PENDING, EXCEPTION (idempotent field update).
     * Prohibited source state: MATCHED — MATCHED→EXCEPTION is not a valid E1000 transition.
     *
     * @throws \InvalidArgumentException if $reason or $owner is empty.
     * @throws \DomainException if the record is in MATCHED state.
     */
    public function exception(
        string $providerKey,
        string $providerReference,
        string $reason,
        string $owner,
    ): void;
}
