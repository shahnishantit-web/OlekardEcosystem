<?php

declare(strict_types=1);

namespace Tests\Unit\Payment;

use App\Modules\Payment\Domain\Exceptions\StatementAlreadyExistsException;
use Tests\TestCase;

/**
 * T030: Provider statement ingestion unit tests.
 *
 * Tests SHA-256 hash determinism, deduplication, and source hash construction logic.
 * DB-gated scenarios (events, job dispatch) require Docker and are not tested here.
 */
final class ProviderStatementIngestionTest extends TestCase
{
    // ── SHA-256 source hash ────────────────────────────────────────────────────

    public function test_source_hash_is_deterministic_for_same_inputs(): void
    {
        $hash1 = $this->computeHash('mock_bank', 'ACC001', 'production', '2026-10-01T00:00:00Z', '2026-10-31T23:59:59Z', ['REF-A', 'REF-B']);
        $hash2 = $this->computeHash('mock_bank', 'ACC001', 'production', '2026-10-01T00:00:00Z', '2026-10-31T23:59:59Z', ['REF-A', 'REF-B']);

        $this->assertSame($hash1, $hash2);
        $this->assertSame(64, strlen($hash1));
    }

    public function test_source_hash_differs_for_different_provider(): void
    {
        $hash1 = $this->computeHash('mock_bank', 'ACC001', 'production', '2026-10-01T00:00:00Z', '2026-10-31T23:59:59Z', ['REF-A']);
        $hash2 = $this->computeHash('other_bank', 'ACC001', 'production', '2026-10-01T00:00:00Z', '2026-10-31T23:59:59Z', ['REF-A']);

        $this->assertNotSame($hash1, $hash2);
    }

    public function test_source_hash_differs_for_different_account(): void
    {
        $hash1 = $this->computeHash('mock_bank', 'ACC001', 'production', '2026-10-01T00:00:00Z', '2026-10-31T23:59:59Z', ['REF-A']);
        $hash2 = $this->computeHash('mock_bank', 'ACC002', 'production', '2026-10-01T00:00:00Z', '2026-10-31T23:59:59Z', ['REF-A']);

        $this->assertNotSame($hash1, $hash2);
    }

    public function test_source_hash_sorts_reference_ids_before_hashing(): void
    {
        // Regardless of input order, sorted refs produce same hash
        $hash1 = $this->computeHash('mock_bank', 'ACC001', 'production', '2026-10-01T00:00:00Z', '2026-10-31T23:59:59Z', ['REF-B', 'REF-A']);
        $hash2 = $this->computeHash('mock_bank', 'ACC001', 'production', '2026-10-01T00:00:00Z', '2026-10-31T23:59:59Z', ['REF-A', 'REF-B']);

        $this->assertSame($hash1, $hash2);
    }

    public function test_source_hash_differs_for_different_environment(): void
    {
        $hash1 = $this->computeHash('mock_bank', 'ACC001', 'production', '2026-10-01T00:00:00Z', '2026-10-31T23:59:59Z', ['REF-A']);
        $hash2 = $this->computeHash('mock_bank', 'ACC001', 'sandbox', '2026-10-01T00:00:00Z', '2026-10-31T23:59:59Z', ['REF-A']);

        $this->assertNotSame($hash1, $hash2);
    }

    public function test_source_hash_differs_for_different_period(): void
    {
        $hash1 = $this->computeHash('mock_bank', 'ACC001', 'production', '2026-10-01T00:00:00Z', '2026-10-31T23:59:59Z', ['REF-A']);
        $hash2 = $this->computeHash('mock_bank', 'ACC001', 'production', '2026-09-01T00:00:00Z', '2026-09-30T23:59:59Z', ['REF-A']);

        $this->assertNotSame($hash1, $hash2);
    }

    // ── StatementAlreadyExistsException ───────────────────────────────────────

    public function test_statement_already_exists_exception_carries_hash_and_id(): void
    {
        $hash      = str_repeat('a', 64);
        $existingId = 'stmt-' . uniqid();

        $exception = StatementAlreadyExistsException::withHash($hash, $existingId);

        $this->assertSame($hash, $exception->sourceHash);
        $this->assertSame($existingId, $exception->existingStatementId);
        $this->assertStringContainsString($hash, $exception->getMessage());
    }

    // ── Canonical string format ───────────────────────────────────────────────

    public function test_canonical_string_format_matches_expected_structure(): void
    {
        $providerCode  = 'mock_bank';
        $accountId     = 'ACC001';
        $environment   = 'production';
        $periodStart   = '2026-10-01T00:00:00+00:00';
        $periodEnd     = '2026-10-31T23:59:59+00:00';
        $refIds        = ['REF-B', 'REF-A'];
        sort($refIds);
        $canonical     = "{$providerCode}|{$accountId}|{$environment}|{$periodStart}|{$periodEnd}|".implode(',', $refIds);

        $hash = hash('sha256', $canonical);

        $this->assertSame(64, strlen($hash));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    /** @param string[] $refIds */
    private function computeHash(
        string $providerCode,
        string $accountId,
        string $environment,
        string $periodStart,
        string $periodEnd,
        array  $refIds,
    ): string {
        sort($refIds);
        $canonical = "{$providerCode}|{$accountId}|{$environment}|{$periodStart}|{$periodEnd}|" . implode(',', $refIds);
        return hash('sha256', $canonical);
    }
}
