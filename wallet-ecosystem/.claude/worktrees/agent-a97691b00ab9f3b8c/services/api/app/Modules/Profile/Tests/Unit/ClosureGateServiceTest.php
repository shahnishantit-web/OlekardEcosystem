<?php

declare(strict_types=1);

use App\Modules\Profile\Domain\Contracts\FinancialObligationContract;
use App\Modules\Profile\Domain\Contracts\ObligationCheckResult;
use App\Modules\Profile\Domain\Exceptions\ClosureBlockedByObligationsException;
use App\Modules\Profile\Domain\Services\ClosureGateService;
use App\Modules\Profile\Infrastructure\Stubs\StubFinancialObligationService;
use Ramsey\Uuid\Uuid;

// ---------------------------------------------------------------------------
// T103 — ClosureGateService unit tests
// ---------------------------------------------------------------------------

describe('ClosureGateService', function (): void {

    it('throws ClosureBlockedByObligationsException when stub is active', function (): void {
        $userId  = (string) Uuid::uuid7();
        $service = new ClosureGateService(new StubFinancialObligationService());

        expect(fn () => $service->checkAndEnforce($userId))
            ->toThrow(ClosureBlockedByObligationsException::class);
    });

    it('blocked_by from stub contains obligations_check_unavailable', function (): void {
        $userId  = (string) Uuid::uuid7();
        $service = new ClosureGateService(new StubFinancialObligationService());

        try {
            $service->checkAndEnforce($userId);
            $this->fail('Expected ClosureBlockedByObligationsException was not thrown');
        } catch (ClosureBlockedByObligationsException $e) {
            expect($e->blockedBy)->toBe(['obligations_check_unavailable']);
        }
    });

    it('passes without exception when hasObligations is false', function (): void {
        $userId = (string) Uuid::uuid7();

        $contract = Mockery::mock(FinancialObligationContract::class);
        $contract->shouldReceive('checkObligations')
            ->once()
            ->with($userId)
            ->andReturn(new ObligationCheckResult(
                hasObligations: false,
                blockedBy:      [],
            ));

        $service = new ClosureGateService($contract);

        // Must not throw
        $service->checkAndEnforce($userId);

        expect(true)->toBeTrue(); // reached here without exception
    });

    it('throws with the correct blocked_by array when hasObligations is true', function (): void {
        $userId = (string) Uuid::uuid7();

        $contract = Mockery::mock(FinancialObligationContract::class);
        $contract->shouldReceive('checkObligations')
            ->once()
            ->with($userId)
            ->andReturn(new ObligationCheckResult(
                hasObligations: true,
                blockedBy:      ['wallet_balance', 'active_loan'],
            ));

        $service = new ClosureGateService($contract);

        try {
            $service->checkAndEnforce($userId);
            $this->fail('Expected ClosureBlockedByObligationsException was not thrown');
        } catch (ClosureBlockedByObligationsException $e) {
            expect($e->blockedBy)->toBe(['wallet_balance', 'active_loan']);
        }
    });

    it('calls checkObligations exactly once with the given userId', function (): void {
        $userId = (string) Uuid::uuid7();

        $contract = Mockery::mock(FinancialObligationContract::class);
        $contract->shouldReceive('checkObligations')
            ->once()
            ->with($userId)
            ->andReturn(new ObligationCheckResult(
                hasObligations: false,
                blockedBy:      [],
            ));

        $service = new ClosureGateService($contract);
        $service->checkAndEnforce($userId);
    });

});
