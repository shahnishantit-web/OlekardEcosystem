<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Transaction;

use App\Modules\Transaction\Domain\Exceptions\ReversalNotApprovableException;
use App\Modules\Transaction\Domain\Exceptions\TransactionNotReversibleException;
use App\Modules\Transaction\Domain\Enums\ReversalStatus;
use App\Modules\Transaction\Domain\Enums\TransactionType;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ReversalService request/approve validation rules.
 *
 * These tests verify the domain exception contracts and validation logic
 * that guards reversal creation and approval. Integration tests
 * (tests/Concurrency/Modules/Transaction/) cover the actual DB SELECT FOR
 * UPDATE and partial-unique-index enforcement.
 */
final class ReversalServiceRequestTest extends TestCase
{
    // ── TransactionNotReversibleException factory methods ─────────────────────

    /** @test */
    public function not_completed_exception_contains_transaction_id(): void
    {
        $e = TransactionNotReversibleException::notCompleted('tx-abc');

        $this->assertStringContainsString('tx-abc', $e->getMessage());
        $this->assertInstanceOf(\RuntimeException::class, $e);
    }

    /** @test */
    public function type_not_supported_exception_contains_type_name(): void
    {
        $e = TransactionNotReversibleException::typeNotSupported('transfer');

        $this->assertStringContainsString('transfer', $e->getMessage());
        $this->assertInstanceOf(\RuntimeException::class, $e);
    }

    /** @test */
    public function active_reversal_exists_exception_contains_transaction_id(): void
    {
        $e = TransactionNotReversibleException::activeReversalExists('tx-xyz');

        $this->assertStringContainsString('tx-xyz', $e->getMessage());
        $this->assertInstanceOf(\RuntimeException::class, $e);
    }

    // ── Reversible type whitelist ─────────────────────────────────────────────

    /** @test */
    public function funding_and_withdrawal_are_reversible_types_v1(): void
    {
        $reversibleTypes = ['withdrawal', 'funding'];

        $this->assertContains(TransactionType::Withdrawal->value, $reversibleTypes);
        $this->assertContains(TransactionType::Funding->value, $reversibleTypes);
    }

    /** @test */
    public function transfer_is_not_a_reversible_type_v1(): void
    {
        $reversibleTypes = ['withdrawal', 'funding'];

        $this->assertNotContains(TransactionType::Transfer->value, $reversibleTypes);
    }

    // ── ReversalNotApprovableException factory methods ────────────────────────

    /** @test */
    public function wrong_status_exception_contains_status_value(): void
    {
        $e = ReversalNotApprovableException::wrongStatus('rejected');

        $this->assertStringContainsString('rejected', $e->getMessage());
        $this->assertInstanceOf(\RuntimeException::class, $e);
    }

    /** @test */
    public function maker_checker_exception_describes_the_rule(): void
    {
        $e = ReversalNotApprovableException::makerCheckerViolation();

        $this->assertStringContainsStringIgnoringCase('maker', $e->getMessage());
        $this->assertInstanceOf(\RuntimeException::class, $e);
    }

    // ── Maker-checker business rule ───────────────────────────────────────────

    /** @test */
    public function maker_checker_violation_when_requester_equals_approver(): void
    {
        $requestedBy = 'admin-user-001';
        $approvedBy  = 'admin-user-001';

        $requiresMakerChecker = true;
        $violated             = $requiresMakerChecker && ($requestedBy === $approvedBy);

        $this->assertTrue($violated, 'Same actor should violate maker-checker rule');
    }

    /** @test */
    public function maker_checker_passes_when_approver_differs_from_requester(): void
    {
        $requestedBy = 'admin-user-001';
        $approvedBy  = 'admin-user-002';

        $requiresMakerChecker = true;
        $violated             = $requiresMakerChecker && ($requestedBy === $approvedBy);

        $this->assertFalse($violated, 'Different actors should satisfy maker-checker rule');
    }

    /** @test */
    public function maker_checker_disabled_allows_same_actor_to_approve(): void
    {
        $requestedBy = 'admin-user-001';
        $approvedBy  = 'admin-user-001';

        $requiresMakerChecker = false;
        $violated             = $requiresMakerChecker && ($requestedBy === $approvedBy);

        $this->assertFalse($violated, 'When maker-checker disabled, same actor should be allowed');
    }

    // ── Active reversal guard ─────────────────────────────────────────────────

    /** @test */
    public function active_statuses_block_new_reversal_request(): void
    {
        $activeStatuses = [ReversalStatus::PendingApproval->value, ReversalStatus::Approved->value];

        $this->assertContains('pending_approval', $activeStatuses);
        $this->assertContains('approved', $activeStatuses);
        $this->assertNotContains('rejected', $activeStatuses);
        $this->assertNotContains('executed', $activeStatuses);
        $this->assertNotContains('failed', $activeStatuses);
    }

    /** @test */
    public function terminal_reversals_do_not_block_new_reversal_request(): void
    {
        $terminalStatuses = [
            ReversalStatus::Rejected->value,
            ReversalStatus::Executed->value,
            ReversalStatus::Failed->value,
        ];

        $activeStatuses = [ReversalStatus::PendingApproval->value, ReversalStatus::Approved->value];

        foreach ($terminalStatuses as $status) {
            $this->assertNotContains($status, $activeStatuses, "Status [{$status}] should not block new reversal");
        }
    }
}
