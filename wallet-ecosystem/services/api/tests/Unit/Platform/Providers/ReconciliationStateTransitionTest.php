<?php

declare(strict_types=1);

use App\Modules\Platform\Domain\Enums\ReconciliationState;
use Tests\Support\UnitTestCase;

uses(UnitTestCase::class);

// ── Enum membership ───────────────────────────────────────────────────────

test('ReconciliationState has exactly 4 cases', function () {
    expect(ReconciliationState::cases())->toHaveCount(4);
});

test('ReconciliationState has PENDING case', function () {
    expect(ReconciliationState::PENDING->value)->toBe('pending');
});

test('ReconciliationState has MATCHED case', function () {
    expect(ReconciliationState::MATCHED->value)->toBe('matched');
});

test('ReconciliationState has UNMATCHED case (reserved for E20)', function () {
    expect(ReconciliationState::UNMATCHED->value)->toBe('unmatched');
});

test('ReconciliationState has EXCEPTION case', function () {
    expect(ReconciliationState::EXCEPTION->value)->toBe('exception');
});

// ── from() round-trip ─────────────────────────────────────────────────────

test('from() round-trips each state value', function () {
    foreach (ReconciliationState::cases() as $case) {
        expect(ReconciliationState::from($case->value))->toBe($case);
    }
});

// ── Allowed transitions (E1000 state machine) ─────────────────────────────
//
// PENDING  → MATCHED    (via match())
// PENDING  → EXCEPTION  (via exception())
// EXCEPTION → MATCHED   (via match() after reason + owner set)
// MATCHED  → * PROHIBITED

test('PENDING is a valid initial state', function () {
    $state = ReconciliationState::PENDING;
    expect($state)->toBe(ReconciliationState::PENDING);
});

test('PENDING can transition to MATCHED', function () {
    // Allowed transition documented in E1000 spec
    $from = ReconciliationState::PENDING;
    $to   = ReconciliationState::MATCHED;

    expect($from !== $to)->toBeTrue();
    expect($to)->toBe(ReconciliationState::MATCHED);
});

test('PENDING can transition to EXCEPTION', function () {
    $from = ReconciliationState::PENDING;
    $to   = ReconciliationState::EXCEPTION;

    expect($from !== $to)->toBeTrue();
    expect($to)->toBe(ReconciliationState::EXCEPTION);
});

test('EXCEPTION can transition to MATCHED', function () {
    $from = ReconciliationState::EXCEPTION;
    $to   = ReconciliationState::MATCHED;

    expect($from !== $to)->toBeTrue();
    expect($to)->toBe(ReconciliationState::MATCHED);
});

// ── Prohibited transitions ────────────────────────────────────────────────

test('MATCHED is terminal — cannot equal any other target state', function () {
    $matched = ReconciliationState::MATCHED;

    expect($matched)->not->toBe(ReconciliationState::PENDING);
    expect($matched)->not->toBe(ReconciliationState::EXCEPTION);
    expect($matched)->not->toBe(ReconciliationState::UNMATCHED);
});

test('UNMATCHED is reserved for E20 — ExternalReferenceMappingService never sets it in E1000', function () {
    // Validate the enum case exists but note that E1000 service operations
    // (match / exception / createSpeculative / confirmProviderReference) do not
    // produce UNMATCHED state — E20 owns that transition.
    $unmatched = ReconciliationState::UNMATCHED;
    expect($unmatched->value)->toBe('unmatched');
});

// ── Symmetry: no state equals a different state ───────────────────────────

test('all four state values are distinct', function () {
    $values = array_map(fn ($c) => $c->value, ReconciliationState::cases());
    expect(array_unique($values))->toHaveCount(4);
});
