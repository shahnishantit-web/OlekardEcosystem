<?php

declare(strict_types=1);

/**
 * Concurrency tests for ExternalReferenceMappingService.
 *
 * pcntl_fork() is not available on Windows. These tests instead exercise the same
 * database-level invariants by injecting a conflicting row *before* calling the
 * service — identical to what happens when a concurrent process wins the race.
 * The expected exceptions and deduplication paths are the same regardless of
 * whether the conflict originates from a true parallel process or direct DB insert.
 */

use App\Modules\Platform\Application\Services\ExternalReferenceMappingService;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Enums\ReconciliationState;
use App\Modules\Platform\Domain\Exceptions\ImmutableReferenceViolationException;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Support\PlatformTestCase;

uses(PlatformTestCase::class);

// Helper: raw insert bypassing the service (simulates a concurrent process)
function rawInsertPending(
    string $id,
    string $entityId,
    string $entityType,
    string $providerKey,
    string $opType,
    ?string $providerReference = null,
): void {
    DB::connection('platform')->table('platform.provider_external_references')->insert([
        'id'                    => $id,
        'platform_entity_id'    => $entityId,
        'platform_entity_type'  => $entityType,
        'provider_key'          => $providerKey,
        'operation_type'        => $opType,
        'provider_reference'    => $providerReference,
        'reconciliation_state'  => 'pending',
        'created_at'            => now('UTC')->format('Y-m-d H:i:s.u'),
    ]);
}

beforeEach(function () {
    DB::connection('platform')->table('platform.provider_external_references')->truncate();
    $audit = mock(AuditServiceInterface::class);
    $audit->shouldReceive('record')->andReturn(null)->byDefault();
    $this->service = new ExternalReferenceMappingService($audit);
});

// ── Test 1 ── confirmProviderReference() race ─────────────────────────────
//
// Scenario: two concurrent callers both have the same speculative record ID
// and both try to confirmProviderReference with the same (or different) values.
//
// Invariant: the first writer succeeds; every subsequent call on the same record
// MUST throw ImmutableReferenceViolationException — regardless of the proposed
// providerReference value. This is enforced by the one-time-write check on
// `provider_reference IS NULL` in the service layer.

test('confirmProviderReference() — loser of a race throws ImmutableReferenceViolationException', function () {
    $mapping = $this->service->createSpeculative(
        (string) Uuid::uuid7(), 'transaction', 'mno_primary', 'initiate_payment',
    );

    // Simulate: first (winning) process writes the reference directly in the DB
    DB::connection('platform')
        ->table('platform.provider_external_references')
        ->where('id', $mapping->id)
        ->whereNull('provider_reference')
        ->update([
            'provider_reference'     => 'TXN-RACE-WIN-001',
            'reference_confirmed_at' => now('UTC')->format('Y-m-d H:i:s.u'),
        ]);

    // Simulate: second (losing) process now calls the service — must throw
    expect(fn () => $this->service->confirmProviderReference(
        $mapping->id,
        'TXN-RACE-LOSE-001',
        new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
    ))->toThrow(ImmutableReferenceViolationException::class);

    // Winning reference is preserved — not overwritten
    $row = DB::connection('platform')
        ->table('platform.provider_external_references')
        ->where('id', $mapping->id)
        ->first();

    expect($row->provider_reference)->toBe('TXN-RACE-WIN-001');
});

test('confirmProviderReference() — loser race with same providerReference also throws ImmutableReferenceViolationException', function () {
    $mapping = $this->service->createSpeculative(
        (string) Uuid::uuid7(), 'transaction', 'mno_primary', 'initiate_payment',
    );

    DB::connection('platform')
        ->table('platform.provider_external_references')
        ->where('id', $mapping->id)
        ->whereNull('provider_reference')
        ->update([
            'provider_reference'     => 'TXN-RACE-SAME',
            'reference_confirmed_at' => now('UTC')->format('Y-m-d H:i:s.u'),
        ]);

    expect(fn () => $this->service->confirmProviderReference(
        $mapping->id,
        'TXN-RACE-SAME',
        new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
    ))->toThrow(ImmutableReferenceViolationException::class);
});

test('confirmProviderReference() — UNIQUE(provider_key, provider_reference) prevents the same reference on a different record', function () {
    $m1 = $this->service->createSpeculative((string) Uuid::uuid7(), 'transaction', 'mno_primary', 'initiate_payment');
    $m2 = $this->service->createSpeculative((string) Uuid::uuid7(), 'transaction', 'mno_primary', 'initiate_payment');

    $this->service->confirmProviderReference(
        $m1->id,
        'TXN-UNIQUE-RACE',
        new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
    );

    expect(fn () => $this->service->confirmProviderReference(
        $m2->id,
        'TXN-UNIQUE-RACE',
        new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
    ))->toThrow(ImmutableReferenceViolationException::class);
});

// ── Test 2 ── createSpeculative() race ────────────────────────────────────
//
// Scenario: two concurrent callers both try to createSpeculative with the same
// (platformEntityId, platformEntityType, providerKey, operationType).
//
// Invariant: exactly ONE PENDING row is inserted; both callers return the same
// record. Enforced by the partial unique index `per_speculative_unique` plus
// the UniqueConstraintViolationException catch in createSpeculative().

test('createSpeculative() — concurrent insert conflict resolves to the same PENDING record', function () {
    $entityId   = (string) Uuid::uuid7();
    $entityType = 'transaction';
    $provider   = 'mno_primary';
    $operation  = 'initiate_payment';

    // Simulate: first process created and committed the row before our call
    $winningId = (string) Uuid::uuid7();
    rawInsertPending($winningId, $entityId, $entityType, $provider, $operation);

    // Our call: the service must handle the unique constraint violation and
    // return the existing (winning) row — not throw or insert a duplicate
    $result = $this->service->createSpeculative($entityId, $entityType, $provider, $operation);

    expect($result->id)->toBe($winningId);

    $count = DB::connection('platform')
        ->table('platform.provider_external_references')
        ->where('platform_entity_id', $entityId)
        ->where('reconciliation_state', 'pending')
        ->count();

    expect($count)->toBe(1);
});

test('createSpeculative() — multiple sequential calls return the same PENDING row', function () {
    $entityId  = (string) Uuid::uuid7();
    $provider  = 'mno_primary';
    $operation = 'initiate_payment';

    $first  = $this->service->createSpeculative($entityId, 'transaction', $provider, $operation);
    $second = $this->service->createSpeculative($entityId, 'transaction', $provider, $operation);
    $third  = $this->service->createSpeculative($entityId, 'transaction', $provider, $operation);

    expect($second->id)->toBe($first->id);
    expect($third->id)->toBe($first->id);

    $count = DB::connection('platform')
        ->table('platform.provider_external_references')
        ->where('platform_entity_id', $entityId)
        ->count();

    expect($count)->toBe(1);
});

test('createSpeculative() — non-PENDING (e.g. MATCHED) record does not block creation of a new PENDING row', function () {
    $entityId  = (string) Uuid::uuid7();
    $provider  = 'mno_primary';
    $operation = 'initiate_payment';

    $existingId = (string) Uuid::uuid7();
    DB::connection('platform')->table('platform.provider_external_references')->insert([
        'id'                    => $existingId,
        'platform_entity_id'    => $entityId,
        'platform_entity_type'  => 'transaction',
        'provider_key'          => $provider,
        'operation_type'        => $operation,
        'provider_reference'    => 'HIST-REF-001',
        'reconciliation_state'  => 'matched',
        'reconciled_at'         => now('UTC')->format('Y-m-d H:i:s.u'),
        'created_at'            => now('UTC')->format('Y-m-d H:i:s.u'),
    ]);

    // New speculative record for a retry — must succeed (partial index covers PENDING only)
    $newMapping = $this->service->createSpeculative($entityId, 'transaction', $provider, $operation);

    expect($newMapping->id)->not->toBe($existingId);
    expect($newMapping->reconciliationState)->toBe(ReconciliationState::PENDING);

    $count = DB::connection('platform')
        ->table('platform.provider_external_references')
        ->where('platform_entity_id', $entityId)
        ->count();

    expect($count)->toBe(2);
});

// ── Data integrity ────────────────────────────────────────────────────────

test('matched record is terminal — provider_reference stays immutable even after MATCHED transition', function () {
    $mapping = $this->service->createSpeculative(
        (string) Uuid::uuid7(), 'transaction', 'mno_primary', 'initiate_payment',
    );
    $this->service->confirmProviderReference(
        $mapping->id,
        'TXN-IMMUTABLE-001',
        new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
    );
    $this->service->match('mno_primary', 'TXN-IMMUTABLE-001');

    expect(fn () => $this->service->exception('mno_primary', 'TXN-IMMUTABLE-001', 'reason', 'owner'))
        ->toThrow(\DomainException::class);

    $found = $this->service->findByProviderReference('mno_primary', 'TXN-IMMUTABLE-001');
    expect($found)->not->toBeNull();
    expect($found->providerReference)->toBe('TXN-IMMUTABLE-001');
});
