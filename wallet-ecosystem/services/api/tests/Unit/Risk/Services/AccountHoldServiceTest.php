<?php

declare(strict_types=1);

namespace Tests\Unit\Risk\Services;

use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Risk\Application\Commands\FreezeAccountCommand;
use App\Modules\Risk\Application\Services\AccountHoldService;
use App\Modules\Risk\Domain\Enums\HoldStatus;
use App\Modules\Risk\Domain\Models\AccountHold;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * T108: AccountHoldService unit tests.
 */
final class AccountHoldServiceTest extends TestCase
{
    use DatabaseTransactions;

    private AccountHoldService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $audit = $this->createStub(AuditServiceInterface::class);
        $this->service = new AccountHoldService($audit);
    }

    // ── placeHold ─────────────────────────────────────────────────────────────

    /** placeHold creates an active hold and caches it */
    public function test_place_hold_creates_active_hold(): void
    {
        $cmd = $this->makeCmd();

        $hold = $this->service->placeHold($cmd);

        $this->assertSame(HoldStatus::Active, $hold->status);
        $this->assertDatabaseHas('risk.account_holds', [
            'id'     => $hold->id,
            'status' => 'active',
        ], 'risk');
    }

    /** After placeHold, checkHold returns true (from cache) */
    public function test_place_hold_then_check_hold_returns_true(): void
    {
        $cmd = $this->makeCmd();
        $this->service->placeHold($cmd);

        $result = $this->service->checkHold($cmd->subjectType, $cmd->subjectId);

        $this->assertTrue($result);
    }

    /** placeHold without checker_id throws 422 */
    public function test_place_hold_without_checker_id_throws_422(): void
    {
        try {
            new FreezeAccountCommand(
                subjectType: 'customer',
                subjectId:   Str::uuid()->toString(),
                reason:      'Suspicious activity detected by analyst.',
                placedBy:    Str::uuid()->toString(),
                checkerId:   '',  // empty — service checks null; use constructor with no checker
            );
        } catch (\Throwable) {
            // constructor succeeded; test via service directly
        }

        $cmd = new FreezeAccountCommand(
            subjectType: 'customer',
            subjectId:   Str::uuid()->toString(),
            reason:      'Suspicious activity detected.',
            placedBy:    Str::uuid()->toString(),
            checkerId:   null,
        );

        try {
            $this->service->placeHold($cmd);
            $this->fail('Expected RuntimeException not thrown');
        } catch (\RuntimeException $e) {
            $this->assertSame(422, $e->getCode());
        }
    }

    /** Second placeHold on same subject throws 409 */
    public function test_place_hold_idempotent_duplicate_throws_409(): void
    {
        $cmd = $this->makeCmd();
        $this->service->placeHold($cmd);

        try {
            $this->service->placeHold($cmd);
            $this->fail('Expected RuntimeException not thrown');
        } catch (\RuntimeException $e) {
            $this->assertSame(409, $e->getCode());
        }
    }

    // ── checkHold ─────────────────────────────────────────────────────────────

    /** checkHold returns false when no hold exists */
    public function test_check_hold_returns_false_when_no_hold(): void
    {
        $result = $this->service->checkHold('customer', Str::uuid()->toString());

        $this->assertFalse($result);
    }

    /** checkHold falls back to DB when cache is empty */
    public function test_check_hold_falls_back_to_db(): void
    {
        $subjectId = Str::uuid()->toString();

        // Seed hold directly without going through service (no cache set)
        AccountHold::create([
            'id'           => (string) Str::orderedUuid(),
            'subject_type' => 'customer',
            'subject_id'   => $subjectId,
            'hold_type'    => 'risk_freeze',
            'status'       => HoldStatus::Active->value,
            'reason'       => 'Direct DB seed for test.',
            'placed_by'    => Str::uuid()->toString(),
            'placed_at'    => now(),
        ]);

        Cache::forget("hold_active:customer:{$subjectId}");

        $result = $this->service->checkHold('customer', $subjectId);

        $this->assertTrue($result);
    }

    // ── liftHold ─────────────────────────────────────────────────────────────

    /** liftHold transitions hold to Lifted and clears cache */
    public function test_lift_hold_clears_hold(): void
    {
        $cmd = $this->makeCmd();
        $hold = $this->service->placeHold($cmd);

        $liftedBy   = Str::uuid()->toString();
        $liftReason = 'Investigation complete — no suspicious activity found.';

        $lifted = $this->service->liftHold($hold->id, $liftedBy, $liftReason);

        $this->assertSame(HoldStatus::Lifted, $lifted->status);
        $this->assertFalse($this->service->checkHold($cmd->subjectType, $cmd->subjectId));
    }

    /** liftHold on non-existent hold throws 404 */
    public function test_lift_hold_not_found_throws_404(): void
    {
        try {
            $this->service->liftHold(Str::uuid()->toString(), Str::uuid()->toString(), 'Reason for lifting hold.');
            $this->fail('Expected RuntimeException not thrown');
        } catch (\RuntimeException $e) {
            $this->assertSame(404, $e->getCode());
        }
    }

    /** liftHold on already-lifted hold throws 422 */
    public function test_lift_hold_already_lifted_throws_422(): void
    {
        $cmd  = $this->makeCmd();
        $hold = $this->service->placeHold($cmd);

        $this->service->liftHold($hold->id, Str::uuid()->toString(), 'First lift — investigation complete.');

        try {
            $this->service->liftHold($hold->id, Str::uuid()->toString(), 'Second lift attempt.');
            $this->fail('Expected RuntimeException not thrown');
        } catch (\RuntimeException $e) {
            $this->assertSame(422, $e->getCode());
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeCmd(array $overrides = []): FreezeAccountCommand
    {
        return new FreezeAccountCommand(
            subjectType: $overrides['subjectType'] ?? 'customer',
            subjectId:   $overrides['subjectId']   ?? Str::uuid()->toString(),
            reason:      $overrides['reason']       ?? 'Suspicious activity flagged by risk analyst.',
            placedBy:    $overrides['placedBy']     ?? Str::uuid()->toString(),
            checkerId:   $overrides['checkerId']    ?? Str::uuid()->toString(),
        );
    }
}
