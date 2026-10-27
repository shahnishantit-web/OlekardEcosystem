<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'payment';

    // Frozen migration snapshots, deliberately independent of future enum edits.
    private const PREVIOUS = [
        ['payment_attempts', 'payment_attempts_status_check', 'status', ['initiated','processing','awaiting_action','succeeded','failed','cancelled','expired','timed_out']],
        ['payment_refunds', 'payment_refunds_status_check', 'status', ['requested','pending_approval','processing','succeeded','failed','cancelled']],
        ['reconciliation_exceptions', 'recon_exceptions_type_check', 'exception_type', ['missing_callback','unknown_outcome','amount_mismatch','duplicate_transaction','orphan_provider','failed_refund','unmatched_ledger']],
    ];

    public function up(): void
    {
        $added = [['submitting','unknown'], ['rejected'], ['contradictory_evidence','excess_capture','duplicate_capture','accounting_failure']];
        foreach (self::PREVIOUS as $index => [$table, $constraint, $column, $values]) {
            $this->replaceConstraint($table, $constraint, $column, array_merge($values, $added[$index]));
        }
    }

    public function down(): void
    {
        foreach (self::PREVIOUS as [$table, $constraint, $column, $values]) {
            if (DB::connection('payment')->table('payment.'.$table)->whereNotIn($column, $values)->exists()) {
                throw new RuntimeException('Rollback blocked: newer payment states require verified resolution or archival first.');
            }
        }
        foreach (self::PREVIOUS as [$table, $constraint, $column, $values]) {
            $this->replaceConstraint($table, $constraint, $column, $values);
        }
    }

    private function replaceConstraint(string $table, string $constraint, string $column, array $values): void
    {
        $db = DB::connection('payment');
        $db->statement('ALTER TABLE payment.'.$table.' DROP CONSTRAINT IF EXISTS '.$constraint);
        $quoted = implode(',', array_map(static fn (string $value): string => "'".$value."'", $values));
        $db->statement('ALTER TABLE payment.'.$table.' ADD CONSTRAINT '.$constraint.' CHECK ('.$column.' IN ('.$quoted.'))');
    }
};
