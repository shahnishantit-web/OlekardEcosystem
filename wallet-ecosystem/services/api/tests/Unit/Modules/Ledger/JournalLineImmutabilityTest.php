<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Ledger;

use App\Modules\Ledger\Infrastructure\Models\JournalLineModel;
use PHPUnit\Framework\TestCase;

/**
 * T051 — US-LEDGER-008: JournalLineModel immutability enforcement.
 * Covers: save() on existing throws LogicException; delete() always throws;
 * save() on new (not-yet-persisted) record does NOT throw from the guard.
 */
final class JournalLineImmutabilityTest extends TestCase
{
    // Scenario (a): save() on an existing (already-persisted) JournalLineModel throws LogicException.
    public function test_save_on_existing_journal_line_throws_logic_exception(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/append-only/i');

        $line         = new JournalLineModel();
        $line->exists = true; // simulate a persisted record

        $line->save();
    }

    // Scenario (b): delete() always throws LogicException regardless of $this->exists.
    public function test_delete_on_journal_line_always_throws_logic_exception(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/immutable/i');

        $line         = new JournalLineModel();
        $line->exists = true;

        $line->delete();
    }

    // Scenario (b-new): delete() on a non-persisted line also throws.
    public function test_delete_on_new_journal_line_throws_logic_exception(): void
    {
        $this->expectException(\LogicException::class);

        $line         = new JournalLineModel();
        $line->exists = false;

        $line->delete();
    }

    // Scenario (c): save() on a NOT-yet-persisted (new) JournalLineModel does not throw from the guard.
    // The guard only fires when $this->exists === true.
    // (Actual DB write would fail without a connection — we only verify the guard is not triggered.)
    public function test_save_on_new_journal_line_does_not_throw_immutability_guard(): void
    {
        $line         = new JournalLineModel();
        $line->exists = false;

        $threw = false;

        try {
            // save() will throw a different exception (no DB connection in unit context)
            // but NOT a LogicException from the immutability guard
            $line->save();
        } catch (\LogicException $e) {
            $threw = true;
        } catch (\Throwable) {
            // Expected: DB connection error or similar — NOT the immutability guard
        }

        $this->assertFalse($threw, 'Immutability guard must not fire on a new (not-yet-persisted) JournalLineModel.');
    }
}
