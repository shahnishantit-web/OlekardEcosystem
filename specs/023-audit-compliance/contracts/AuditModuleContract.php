<?php

declare(strict_types=1);

namespace App\Modules\Audit\Domain\Contracts;

use App\Modules\Audit\Domain\ValueObjects\AuditEntry;

/**
 * Cross-module contract for recording audit events.
 *
 * All modules that perform auditable actions inject this contract and call
 * record() within their existing DB::connection('platform')->transaction().
 * The implementation writes to audit.audit_events atomically with the caller's
 * transaction. An AuditEventRecorded integration event is dispatched via outbox
 * after the transaction commits.
 *
 * IMMUTABILITY GUARANTEE: audit events are append-only. This contract exposes
 * no update, delete, or patch operations. Callers MUST NOT attempt to modify
 * a previously recorded event.
 */
interface AuditModuleContract
{
    /**
     * Record an audit event within the current transaction context.
     *
     * @param  AuditEntry $entry  The audit event to record.
     * @return string             The UUID of the written audit event record.
     *
     * @throws \App\Modules\Audit\Domain\Exceptions\AuditWriteException
     *         If the write fails and cannot be retried within the current request.
     */
    public function record(AuditEntry $entry): string;
}
