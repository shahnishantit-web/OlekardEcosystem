<?php

declare(strict_types=1);

use App\Modules\Platform\Application\Services\ExternalReferenceMappingService;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Enums\ReconciliationState;
use App\Modules\Platform\Domain\Exceptions\ImmutableReferenceViolationException;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Support\PlatformTestCase;

uses(PlatformTestCase::class);

/** Generate a unique UUID v7 string for use as a platform entity ID. */
function eid(): string
{
    return (string) Uuid::uuid7();
}

beforeEach(function () {
    DB::connection('platform')->table('platform.provider_external_references')->truncate();
    $this->audit = mock(AuditServiceInterface::class);
    $this->audit->shouldReceive('record')->andReturn(null)->byDefault();
    $this->service = new ExternalReferenceMappingService($this->audit);
});

// ── createSpeculative ──────────────────────────────────────────────────────

test('createSpeculative inserts a row with provider_reference=NULL', function () {
    $id = eid();
    $mapping = $this->service->createSpeculative($id, 'transaction', 'mno_primary', 'initiate_payment');

    expect($mapping->providerReference)->toBeNull();
    expect($mapping->reconciliationState)->toBe(ReconciliationState::PENDING);
    expect($mapping->platformEntityId)->toBe($id);
    expect($mapping->operationType)->toBe('initiate_payment');
});

test('createSpeculative called twice with identical parameters returns the existing PENDING record', function () {
    $id = eid();
    $first  = $this->service->createSpeculative($id, 'transaction', 'mno_primary', 'initiate_payment');
    $second = $this->service->createSpeculative($id, 'transaction', 'mno_primary', 'initiate_payment');

    expect($second->id)->toBe($first->id);

    $count = DB::connection('platform')
        ->table('platform.provider_external_references')
        ->where('platform_entity_id', $id)
        ->where('reconciliation_state', 'pending')
        ->count();

    expect($count)->toBe(1);
});

// ── confirmProviderReference ───────────────────────────────────────────────

test('first confirmProviderReference sets provider_reference and reference_confirmed_at', function () {
    $mapping = $this->service->createSpeculative(eid(), 'transaction', 'mno_primary', 'initiate_payment');

    $confirmed = $this->service->confirmProviderReference(
        $mapping->id,
        'TXN-ABC-001',
        new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
    );

    expect($confirmed->providerReference)->toBe('TXN-ABC-001');
    expect($confirmed->referenceConfirmedAt)->not->toBeNull();
});

test('second confirmProviderReference on same record throws ImmutableReferenceViolationException', function () {
    $mapping = $this->service->createSpeculative(eid(), 'transaction', 'mno_primary', 'initiate_payment');
    $this->service->confirmProviderReference($mapping->id, 'TXN-ABC-002', new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

    expect(fn () => $this->service->confirmProviderReference(
        $mapping->id,
        'TXN-ABC-003',
        new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
    ))->toThrow(ImmutableReferenceViolationException::class);
});

test('confirmProviderReference with a providerReference already used by another record throws ImmutableReferenceViolationException', function () {
    $m1 = $this->service->createSpeculative(eid(), 'transaction', 'mno_primary', 'initiate_payment');
    $m2 = $this->service->createSpeculative(eid(), 'transaction', 'mno_primary', 'initiate_payment');

    $this->service->confirmProviderReference($m1->id, 'SHARED-REF', new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

    expect(fn () => $this->service->confirmProviderReference(
        $m2->id,
        'SHARED-REF',
        new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
    ))->toThrow(ImmutableReferenceViolationException::class);
});

// ── findByProviderReference ───────────────────────────────────────────────

test('findByProviderReference returns the entity after confirmation', function () {
    $mapping = $this->service->createSpeculative(eid(), 'transaction', 'mno_primary', 'initiate_payment');
    $this->service->confirmProviderReference($mapping->id, 'TXN-FIND-001', new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

    $found = $this->service->findByProviderReference('mno_primary', 'TXN-FIND-001');

    expect($found)->not->toBeNull();
    expect($found->providerReference)->toBe('TXN-FIND-001');
});

// ── findByPlatformEntity ─────────────────────────────────────────────────

test('findByPlatformEntity returns all records for entity', function () {
    $sharedId = eid();
    $otherId  = eid();

    $this->service->createSpeculative($sharedId, 'transaction', 'mno_primary', 'initiate_payment');
    $this->service->createSpeculative($sharedId, 'transaction', 'mno_fallback', 'query_balance');
    $this->service->createSpeculative($otherId, 'transaction', 'mno_primary', 'initiate_payment');

    $results = $this->service->findByPlatformEntity($sharedId, 'transaction');
    expect(count($results))->toBe(2);
});

// ── findStalePending ─────────────────────────────────────────────────────

test('findStalePending returns only PENDING records older than threshold', function () {
    $mapping = $this->service->createSpeculative(eid(), 'transaction', 'mno_primary', 'initiate_payment');

    DB::connection('platform')
        ->table('platform.provider_external_references')
        ->where('id', $mapping->id)
        ->update(['created_at' => now()->subHours(2)->format('Y-m-d H:i:s')]);

    $stale = $this->service->findStalePending(new \DateInterval('PT1H'));
    expect(count($stale))->toBeGreaterThanOrEqual(1);

    $ids = array_map(fn ($m) => $m->id, $stale);
    expect($ids)->toContain($mapping->id);
});

// ── findAllExceptions ─────────────────────────────────────────────────────

test('findAllExceptions returns only EXCEPTION records', function () {
    $m1 = $this->service->createSpeculative(eid(), 'transaction', 'mno_primary', 'initiate_payment');
    $this->service->confirmProviderReference($m1->id, 'REF-EXC-001', new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

    $this->service->createSpeculative(eid(), 'transaction', 'mno_primary', 'initiate_payment');

    $this->service->exception('mno_primary', 'REF-EXC-001', 'Manual review required', 'ops-team');

    $exceptions = $this->service->findAllExceptions();
    expect(count($exceptions))->toBe(1);
    expect($exceptions[0]->id)->toBe($m1->id);
});

// ── match ─────────────────────────────────────────────────────────────────

test('match transitions PENDING to MATCHED and sets reconciled_at', function () {
    $m = $this->service->createSpeculative(eid(), 'transaction', 'mno_primary', 'initiate_payment');
    $this->service->confirmProviderReference($m->id, 'REF-MATCH-001', new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

    $this->service->match('mno_primary', 'REF-MATCH-001');

    expect($this->service->getState('mno_primary', 'REF-MATCH-001'))->toBe(ReconciliationState::MATCHED);
    $row = DB::connection('platform')->table('platform.provider_external_references')->where('id', $m->id)->first();
    expect($row->reconciled_at)->not->toBeNull();
});

test('match on already MATCHED record throws DomainException', function () {
    $m = $this->service->createSpeculative(eid(), 'transaction', 'mno_primary', 'initiate_payment');
    $this->service->confirmProviderReference($m->id, 'REF-MATCH-002', new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
    $this->service->match('mno_primary', 'REF-MATCH-002');

    expect(fn () => $this->service->match('mno_primary', 'REF-MATCH-002'))
        ->toThrow(\DomainException::class);
});

test('match on EXCEPTION record without reason/owner throws DomainException', function () {
    $m = $this->service->createSpeculative(eid(), 'transaction', 'mno_primary', 'initiate_payment');
    $this->service->confirmProviderReference($m->id, 'REF-MATCH-003', new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

    DB::connection('platform')->table('platform.provider_external_references')
        ->where('id', $m->id)
        ->update([
            'reconciliation_state'  => 'exception',
            'reconciliation_reason' => null,
            'reconciliation_owner'  => null,
        ]);

    expect(fn () => $this->service->match('mno_primary', 'REF-MATCH-003'))
        ->toThrow(\DomainException::class);
});

// ── exception ─────────────────────────────────────────────────────────────

test('exception with empty reason throws InvalidArgumentException', function () {
    expect(fn () => $this->service->exception('mno_primary', 'REF-X', '', 'owner'))
        ->toThrow(\InvalidArgumentException::class);
});

test('exception with empty owner throws InvalidArgumentException', function () {
    expect(fn () => $this->service->exception('mno_primary', 'REF-X', 'reason', ''))
        ->toThrow(\InvalidArgumentException::class);
});

test('exception on a MATCHED record throws DomainException', function () {
    $m = $this->service->createSpeculative(eid(), 'transaction', 'mno_primary', 'initiate_payment');
    $this->service->confirmProviderReference($m->id, 'REF-EXC-MATCH', new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
    $this->service->match('mno_primary', 'REF-EXC-MATCH');

    expect(fn () => $this->service->exception('mno_primary', 'REF-EXC-MATCH', 'reason', 'owner'))
        ->toThrow(\DomainException::class);
});

// ── getState ──────────────────────────────────────────────────────────────

test('getState throws DomainException for non-existent pair', function () {
    expect(fn () => $this->service->getState('unknown_provider', 'NONEXISTENT-REF'))
        ->toThrow(\DomainException::class);
});

// ── createWithSurrogateReference ──────────────────────────────────────────

test('createWithSurrogateReference creates record with non-null provider_reference and emits audit warning', function () {
    $this->audit->shouldReceive('record')->once()->andReturn(null);

    $mapping = $this->service->createWithSurrogateReference(eid(), 'transaction', 'mno_primary', 'initiate_payment');

    expect($mapping->providerReference)->not->toBeNull();
    expect($mapping->providerReference)->toStartWith('surrogate-');
});

// ── Reconciliation state machine (US5 — added here per T041 instruction) ──

describe('reconciliation state machine', function () {

    test('PENDING to MATCHED via match() sets reconciled_at', function () {
        $m = $this->service->createSpeculative(eid(), 'transaction', 'mno_primary', 'initiate_payment');
        $this->service->confirmProviderReference($m->id, 'R-REF-001', new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

        $this->service->match('mno_primary', 'R-REF-001');

        $row = DB::connection('platform')->table('platform.provider_external_references')->where('id', $m->id)->first();
        expect($row->reconciliation_state)->toBe('matched');
        expect($row->reconciled_at)->not->toBeNull();
    });

    test('PENDING to EXCEPTION via exception() requires non-empty reason and owner', function () {
        $m = $this->service->createSpeculative(eid(), 'transaction', 'mno_primary', 'initiate_payment');
        $this->service->confirmProviderReference($m->id, 'R-REF-002', new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

        $this->service->exception('mno_primary', 'R-REF-002', 'Manual review', 'ops-team');

        expect($this->service->getState('mno_primary', 'R-REF-002'))->toBe(ReconciliationState::EXCEPTION);
    });

    test('EXCEPTION to MATCHED via match() succeeds when reason and owner are already set', function () {
        $m = $this->service->createSpeculative(eid(), 'transaction', 'mno_primary', 'initiate_payment');
        $this->service->confirmProviderReference($m->id, 'R-REF-003', new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $this->service->exception('mno_primary', 'R-REF-003', 'Original reason', 'ops-team');

        $this->service->match('mno_primary', 'R-REF-003');

        expect($this->service->getState('mno_primary', 'R-REF-003'))->toBe(ReconciliationState::MATCHED);
    });

    test('MATCHED to EXCEPTION is prohibited — exception() on MATCHED throws DomainException', function () {
        $m = $this->service->createSpeculative(eid(), 'transaction', 'mno_primary', 'initiate_payment');
        $this->service->confirmProviderReference($m->id, 'R-REF-004', new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $this->service->match('mno_primary', 'R-REF-004');

        expect(fn () => $this->service->exception('mno_primary', 'R-REF-004', 'late review', 'ops'))
            ->toThrow(\DomainException::class);
    });

    test('MATCHED to MATCHED is prohibited — match() on MATCHED throws DomainException', function () {
        $m = $this->service->createSpeculative(eid(), 'transaction', 'mno_primary', 'initiate_payment');
        $this->service->confirmProviderReference($m->id, 'R-REF-005', new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $this->service->match('mno_primary', 'R-REF-005');

        expect(fn () => $this->service->match('mno_primary', 'R-REF-005'))
            ->toThrow(\DomainException::class);
    });

    test('no-silent-resolution — exception() with empty reason throws InvalidArgumentException', function () {
        expect(fn () => $this->service->exception('mno_primary', 'any-ref', '', 'ops'))
            ->toThrow(\InvalidArgumentException::class);
    });

    test('no-silent-resolution — exception() with empty owner throws InvalidArgumentException', function () {
        expect(fn () => $this->service->exception('mno_primary', 'any-ref', 'reason', ''))
            ->toThrow(\InvalidArgumentException::class);
    });

    test('getState throws DomainException for non-existent pair', function () {
        expect(fn () => $this->service->getState('no_provider', 'NO-REF'))
            ->toThrow(\DomainException::class);
    });
});
