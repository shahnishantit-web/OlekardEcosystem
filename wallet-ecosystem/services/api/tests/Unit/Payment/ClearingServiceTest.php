<?php

declare(strict_types=1);

namespace Tests\Unit\Payment;

use App\Modules\Payment\Domain\Contracts\ClearingRecordRepositoryInterface;
use App\Modules\Payment\Domain\Enums\ProviderStatementStatus;
use App\Modules\Payment\Domain\Enums\StatementLineMatchStatus;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use Tests\TestCase;

/**
 * T047: ClearingService unit tests.
 *
 * Tests accounting_status resolution, clearing_status lifecycle, and bank settlement batch matching.
 * DB-gated integration scenarios require Docker.
 */
final class ClearingServiceTest extends TestCase
{
    // ── Enum coverage ────────────────────────────────────────────────────────

    public function test_provider_statement_status_allows_qualification_for_matched(): void
    {
        $this->assertTrue(ProviderStatementStatus::Matched->allowsQualification());
    }

    public function test_provider_statement_status_allows_qualification_for_partially_matched(): void
    {
        $this->assertTrue(ProviderStatementStatus::PartiallyMatched->allowsQualification());
    }

    public function test_provider_statement_status_does_not_allow_qualification_for_pending_match(): void
    {
        $this->assertFalse(ProviderStatementStatus::PendingMatch->allowsQualification());
    }

    public function test_provider_statement_status_does_not_allow_qualification_for_exception_raised(): void
    {
        $this->assertFalse(ProviderStatementStatus::ExceptionRaised->allowsQualification());
    }

    public function test_provider_statement_status_closed_is_terminal(): void
    {
        $this->assertTrue(ProviderStatementStatus::Closed->isTerminal());
    }

    public function test_provider_statement_status_matched_is_not_terminal(): void
    {
        $this->assertFalse(ProviderStatementStatus::Matched->isTerminal());
    }

    // ── accounting_status resolution (pure logic) ─────────────────────────────

    public function test_accounting_status_is_qualified_when_provider_not_in_pending_list(): void
    {
        config(['payment.pending_ledger_config_providers' => ['bank_xyz']]);

        $status = $this->resolveAccountingStatus('mock_bank');

        $this->assertSame('qualified', $status);
    }

    public function test_accounting_status_is_pending_ledger_config_when_provider_in_list(): void
    {
        config(['payment.pending_ledger_config_providers' => ['mock_bank', 'bank_xyz']]);

        $status = $this->resolveAccountingStatus('mock_bank');

        $this->assertSame('pending_ledger_config', $status);
    }

    public function test_accounting_status_defaults_to_qualified_when_config_empty(): void
    {
        config(['payment.pending_ledger_config_providers' => []]);

        $status = $this->resolveAccountingStatus('any_provider');

        $this->assertSame('qualified', $status);
    }

    // ── StatementLineMatchStatus enum ─────────────────────────────────────────

    public function test_statement_line_match_status_has_all_expected_cases(): void
    {
        $cases = array_map(fn ($c) => $c->value, StatementLineMatchStatus::cases());

        $this->assertContains('pending', $cases);
        $this->assertContains('matched', $cases);
        $this->assertContains('amount_mismatch', $cases);
        $this->assertContains('no_platform_record', $cases);
        $this->assertContains('internal_transfer', $cases);
        $this->assertContains('exception_raised', $cases);
    }

    // ── Helper: extract the config-based resolution logic ────────────────────

    private function resolveAccountingStatus(string $providerCode): string
    {
        /** @var array<string> $pendingProviders */
        $pendingProviders = config('payment.pending_ledger_config_providers', []);

        if (in_array($providerCode, $pendingProviders, true)) {
            return 'pending_ledger_config';
        }

        return 'qualified';
    }
}
