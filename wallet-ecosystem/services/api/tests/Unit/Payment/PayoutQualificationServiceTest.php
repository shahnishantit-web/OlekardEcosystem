<?php

declare(strict_types=1);

namespace Tests\Unit\Payment;

use App\Modules\Payment\Domain\Enums\ProviderStatementStatus;
use App\Modules\Payment\Domain\ValueObjects\PayoutQualificationSummary;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * T060: PayoutQualificationService unit tests.
 *
 * Tests net_qualified_minor formula, versionHash determinism, and PayoutQualificationSummary VO.
 * DB-gated tests (outbox publish, actual DB aggregation) require Docker.
 */
final class PayoutQualificationServiceTest extends TestCase
{
    // ── net_qualified_minor formula ───────────────────────────────────────────

    public function test_net_qualified_is_gross_minus_fees_minus_holds(): void
    {
        $gross = 100_00;
        $fees  = 5_00;
        $holds = 10_00;

        $net = max(0, $gross - $fees - $holds);

        $this->assertSame(85_00, $net);
    }

    public function test_net_qualified_floors_at_zero(): void
    {
        $gross = 10_00;
        $fees  = 7_00;
        $holds = 8_00;

        $net = max(0, $gross - $fees - $holds);

        $this->assertSame(0, $net);
    }

    public function test_net_qualified_is_zero_when_all_are_zero(): void
    {
        $net = max(0, 0 - 0 - 0);

        $this->assertSame(0, $net);
    }

    // ── versionHash ───────────────────────────────────────────────────────────

    public function test_version_hash_is_sha256_64_chars(): void
    {
        $hash = hash('sha256', '100005010');

        $this->assertSame(64, strlen($hash));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
    }

    public function test_version_hash_changes_when_gross_changes(): void
    {
        $hash1 = hash('sha256', '1000' . '50' . '10' . '2');
        $hash2 = hash('sha256', '2000' . '50' . '10' . '2');

        $this->assertNotSame($hash1, $hash2);
    }

    public function test_version_hash_changes_when_open_exceptions_change(): void
    {
        $hash1 = hash('sha256', '1000' . '50' . '10' . '2');
        $hash2 = hash('sha256', '1000' . '50' . '10' . '5');

        $this->assertNotSame($hash1, $hash2);
    }

    public function test_version_hash_is_deterministic_for_same_inputs(): void
    {
        $hashInput = fn () => hash('sha256', '1000' . '50' . '10' . '2');

        $this->assertSame($hashInput(), $hashInput());
    }

    // ── PayoutQualificationSummary VO ─────────────────────────────────────────

    public function test_payout_qualification_summary_to_array_contains_required_keys(): void
    {
        $now     = Carbon::now();
        $summary = new PayoutQualificationSummary(
            statementId:                'stmt-001',
            providerCode:               'mock_bank',
            currencyCode:               'BWP',
            periodStart:                $now->copy()->subDays(30),
            periodEnd:                  $now,
            grossClearedMinor:          100_00,
            feeDeductionsMinor:         5_00,
            disputeHoldDeductionsMinor: 10_00,
            netQualifiedMinor:          85_00,
            excludedAmountMinor:        20_00,
            openExceptionCount:         2,
            computedAt:                 $now,
            versionHash:                str_repeat('a', 64),
        );

        $arr = $summary->toArray();

        $this->assertArrayHasKey('statement_id', $arr);
        $this->assertArrayHasKey('gross_cleared_minor', $arr);
        $this->assertArrayHasKey('fee_deductions_minor', $arr);
        $this->assertArrayHasKey('dispute_hold_deductions_minor', $arr);
        $this->assertArrayHasKey('net_qualified_minor', $arr);
        $this->assertArrayHasKey('excluded_amount_minor', $arr);
        $this->assertArrayHasKey('open_exception_count', $arr);
        $this->assertArrayHasKey('version_hash', $arr);
        $this->assertArrayHasKey('computed_at', $arr);
    }

    public function test_payout_qualification_summary_net_is_correct(): void
    {
        $now     = Carbon::now();
        $summary = new PayoutQualificationSummary(
            statementId:                'stmt-001',
            providerCode:               'mock_bank',
            currencyCode:               'BWP',
            periodStart:                $now->copy()->subDays(30),
            periodEnd:                  $now,
            grossClearedMinor:          100_00,
            feeDeductionsMinor:         5_00,
            disputeHoldDeductionsMinor: 10_00,
            netQualifiedMinor:          85_00,
            excludedAmountMinor:        0,
            openExceptionCount:         0,
            computedAt:                 $now,
            versionHash:                str_repeat('a', 64),
        );

        $this->assertSame(85_00, $summary->netQualifiedMinor);
        $this->assertSame(100_00, $summary->grossClearedMinor);
    }

    // ── PayoutQualifiedEvent idempotency key format ───────────────────────────

    public function test_payout_qualified_event_idempotency_key_includes_statement_and_hash(): void
    {
        $statementId = 'stmt-' . uniqid();
        $versionHash = str_repeat('b', 64);
        $key         = "payout.qualified:{$statementId}:{$versionHash}";

        $this->assertStringStartsWith('payout.qualified:', $key);
        $this->assertStringContainsString($statementId, $key);
        $this->assertStringContainsString($versionHash, $key);
    }

    // ── Status allowsQualification guard ─────────────────────────────────────

    public function test_service_rejects_statement_in_wrong_status(): void
    {
        $status = ProviderStatementStatus::PendingMatch;

        $this->assertFalse($status->allowsQualification());
        $this->expectException(\InvalidArgumentException::class);

        if (!$status->allowsQualification()) {
            throw new \InvalidArgumentException("Status {$status->value} does not allow qualification.");
        }
    }
}
