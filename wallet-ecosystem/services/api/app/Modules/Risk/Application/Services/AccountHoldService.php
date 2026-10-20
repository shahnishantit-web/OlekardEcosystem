<?php

declare(strict_types=1);

namespace App\Modules\Risk\Application\Services;

use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Risk\Application\Commands\FreezeAccountCommand;
use App\Modules\Risk\Domain\Enums\HoldStatus;
use App\Modules\Risk\Domain\Enums\HoldType;
use App\Modules\Risk\Domain\Models\AccountHold;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AccountHoldService
{
    private const CACHE_TTL_INDEFINITE = 86_400 * 365; // 1 year for holds with no expiry

    public function __construct(
        private readonly AuditServiceInterface $audit,
    ) {}

    /**
     * Place an account hold (freeze). Maker-checker: checker_id required.
     *
     * @throws \RuntimeException when checker_id is missing or hold already active
     */
    public function placeHold(FreezeAccountCommand $cmd): AccountHold
    {
        if ($cmd->checkerId === null) {
            throw new \RuntimeException('checker_id is required to place an account hold.', 422);
        }

        return DB::connection('risk')->transaction(function () use ($cmd): AccountHold {
            DB::connection('risk')->select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$cmd->subjectType.':'.$cmd->subjectId]);
            $existing = AccountHold::active()
                ->where('subject_type', $cmd->subjectType)
                ->where('subject_id', $cmd->subjectId)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                throw new \RuntimeException('Subject already has an active hold.', 409);
            }

            $ttl = $cmd->expiresAt !== null
                ? now()->diffInSeconds(Carbon::parse($cmd->expiresAt))
                : self::CACHE_TTL_INDEFINITE;

            $hold = AccountHold::create([
                'id' => (string) Str::orderedUuid(),
                'subject_type' => $cmd->subjectType,
                'subject_id' => $cmd->subjectId,
                'wallet_id' => $cmd->walletId,
                'hold_type' => HoldType::RiskFreeze->value,
                'status' => HoldStatus::Active->value,
                'reason' => $cmd->reason,
                'alert_id' => $cmd->alertId,
                'decision_id' => $cmd->decisionId,
                'placed_by' => $cmd->placedBy,
                'placed_at' => now(),
                'expires_at' => $cmd->expiresAt,
            ]);

            // Never publish a cached freeze before its owning transaction commits.
            DB::connection('risk')->afterCommit(function () use ($cmd, $ttl): void {
                Cache::put($this->cacheKey($cmd->subjectType, $cmd->subjectId), true, (int) $ttl);
            });

            $this->audit->record(new AuditEventData(
                action: 'risk.account.frozen',
                actorType: 'user',
                resourceType: 'account_hold',
                correlationId: (string) $hold->id,
                outcome: 'success',
                metadata: [
                    'hold_id' => $hold->id,
                    'subject_type' => $cmd->subjectType,
                    'subject_id' => $cmd->subjectId,
                    'placed_by' => $cmd->placedBy,
                    'checker_id' => $cmd->checkerId,
                    'alert_id' => $cmd->alertId,
                ],
            ));

            return $hold;
        });
    }

    /**
     * Check whether a subject has an active hold. Redis-first with DB fallback.
     */
    public function checkHold(string $subjectType, string $subjectId): bool
    {
        $cacheKey = $this->cacheKey($subjectType, $subjectId);

        if (Cache::has($cacheKey)) {
            return (bool) Cache::get($cacheKey);
        }

        $exists = AccountHold::active()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->exists();

        if ($exists) {
            Cache::put($cacheKey, true, self::CACHE_TTL_INDEFINITE);
        }

        return $exists;
    }

    /**
     * Lift an existing hold (unfreeze).
     *
     * @throws \RuntimeException when hold not found or already lifted
     */
    public function liftHold(string $holdId, string $liftedBy, string $liftReason): AccountHold
    {
        return DB::connection('risk')->transaction(function () use ($holdId, $liftedBy, $liftReason): AccountHold {
            $hold = AccountHold::lockForUpdate()->find($holdId);

            if ($hold === null) {
                throw new \RuntimeException('Hold not found.', 404);
            }

            if ($hold->status !== HoldStatus::Active) {
                throw new \RuntimeException('Hold is not active.', 422);
            }

            $hold->update([
                'status' => HoldStatus::Lifted->value,
                'lifted_by' => $liftedBy,
                'lifted_at' => now(),
                'lift_reason' => $liftReason,
            ]);

            Cache::forget($this->cacheKey($hold->subject_type, $hold->subject_id));

            $this->audit->record(new AuditEventData(
                action: 'risk.account.hold_lifted',
                actorType: 'user',
                resourceType: 'account_hold',
                correlationId: (string) $holdId,
                outcome: 'success',
                metadata: [
                    'hold_id' => $holdId,
                    'subject_type' => $hold->subject_type,
                    'subject_id' => $hold->subject_id,
                    'lifted_by' => $liftedBy,
                    'lift_reason' => $liftReason,
                ],
            ));

            return $hold->fresh();
        });
    }

    private function cacheKey(string $subjectType, string $subjectId): string
    {
        return "hold_active:{$subjectType}:{$subjectId}";
    }
}
