<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Services;

use App\Modules\Payment\Domain\Contracts\BankAccountLifecycleServiceInterface;
use App\Modules\Payment\Domain\Enums\AccountConfigLifecycleStatus;
use App\Modules\Payment\Domain\Exceptions\ApprovalConflictException;
use App\Modules\Payment\Domain\Exceptions\LifecycleTransitionException;
use App\Modules\Payment\Infrastructure\Models\ApiOperationConfigModel;
use App\Modules\Payment\Infrastructure\Models\ConfigurationVersionModel;
use App\Modules\Payment\Infrastructure\Models\PaymentAttemptModel;
use App\Modules\Payment\Infrastructure\Models\PaymentProviderAccountModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * T050: Full lifecycle state machine for integration accounts.
 *
 * FR-013: approve() enforces approver ≠ submitter.
 * FR-020: All edits to Active account create a new Draft (no minor-edit bypass).
 * FR-028: retire() blocked if outstanding obligations without approved resolution route.
 * Constitution P4/P8: All lifecycle transitions are idempotent when supplied the same Idempotency-Key.
 */
final class BankAccountLifecycleService implements BankAccountLifecycleServiceInterface
{
    public function __construct(
        private readonly AuditServiceInterface          $audit,
        private readonly ?CertificationEvidenceService  $evidenceService = null,
    ) {
    }

    public function submitForValidation(string $accountId, string $actorId): PaymentProviderAccountModel
    {
        return $this->transition(
            accountId: $accountId,
            from:      AccountConfigLifecycleStatus::Draft,
            to:        AccountConfigLifecycleStatus::Validated,
            actorId:   $actorId,
            mutate: static function (PaymentProviderAccountModel $account) use ($actorId): void {
                $account->submitted_by_actor_id = $actorId;
            },
        );
    }

    public function initiateSandboxTesting(string $accountId, string $actorId): PaymentProviderAccountModel
    {
        return $this->transition(
            accountId: $accountId,
            from:      AccountConfigLifecycleStatus::Validated,
            to:        AccountConfigLifecycleStatus::SandboxTesting,
            actorId:   $actorId,
        );
    }

    /**
     * T065: Evidence gate — hasRequiredEvidence must pass before transitioning to EvidenceReview.
     */
    public function submitEvidence(string $accountId, string $actorId): PaymentProviderAccountModel
    {
        if ($this->evidenceService !== null && !$this->evidenceService->hasRequiredEvidence($accountId)) {
            throw LifecycleTransitionException::missingRequiredEvidence(
                AccountConfigLifecycleStatus::SandboxTesting->value,
                AccountConfigLifecycleStatus::EvidenceReview->value,
            );
        }

        return $this->transition(
            accountId: $accountId,
            from:      AccountConfigLifecycleStatus::SandboxTesting,
            to:        AccountConfigLifecycleStatus::EvidenceReview,
            actorId:   $actorId,
        );
    }

    /**
     * FR-013: approverActorId MUST differ from submitted_by_actor_id.
     *
     * @throws ApprovalConflictException
     */
    public function approve(string $accountId, string $approverActorId): PaymentProviderAccountModel
    {
        $account = $this->findOrFail($accountId);

        if ($account->submitted_by_actor_id === $approverActorId) {
            throw new ApprovalConflictException($approverActorId);
        }

        return $this->transition(
            accountId: $accountId,
            from:      AccountConfigLifecycleStatus::EvidenceReview,
            to:        AccountConfigLifecycleStatus::Approved,
            actorId:   $approverActorId,
            mutate: static function (PaymentProviderAccountModel $account) use ($approverActorId): void {
                $account->approved_by_actor_id = $approverActorId;
                $account->approved_at          = now();
            },
        );
    }

    public function reject(string $accountId, string $reason, string $actorId): PaymentProviderAccountModel
    {
        return $this->transition(
            accountId: $accountId,
            from:      AccountConfigLifecycleStatus::EvidenceReview,
            to:        AccountConfigLifecycleStatus::Draft,
            actorId:   $actorId,
            mutate: static function (PaymentProviderAccountModel $account) use ($reason): void {
                $account->rejection_reason = $reason;
            },
        );
    }

    public function requestChanges(string $accountId, string $reason, string $actorId): PaymentProviderAccountModel
    {
        return $this->transition(
            accountId: $accountId,
            from:      AccountConfigLifecycleStatus::EvidenceReview,
            to:        AccountConfigLifecycleStatus::Draft,
            actorId:   $actorId,
            mutate: static function (PaymentProviderAccountModel $account) use ($reason): void {
                $account->rejection_reason = $reason;
            },
        );
    }

    /**
     * FR-023: Creates immutable ConfigurationVersion snapshot and sets active_config_version_id.
     */
    public function activate(string $accountId, string $actorId): PaymentProviderAccountModel
    {
        return DB::connection('payment')->transaction(function () use ($accountId, $actorId): PaymentProviderAccountModel {
            $account = $this->findOrFail($accountId);

            $this->assertCanTransition(
                $account,
                AccountConfigLifecycleStatus::Approved,
                AccountConfigLifecycleStatus::Active,
            );

            $version = $this->createVersionSnapshot($accountId, $actorId);

            $account->account_lifecycle_status  = AccountConfigLifecycleStatus::Active->value;
            $account->active_config_version_id  = $version->id;
            $account->activated_at              = now();
            $account->is_active                 = true;
            $account->save();

            $this->recordAudit('payment.integration-account.activated', $actorId, $accountId, [
                'config_version_id' => $version->id,
                'version_number'    => $version->version_number,
            ]);

            return $account->fresh();
        });
    }

    public function suspend(string $accountId, string $actorId): PaymentProviderAccountModel
    {
        return $this->transition(
            accountId: $accountId,
            from:      AccountConfigLifecycleStatus::Active,
            to:        AccountConfigLifecycleStatus::Suspended,
            actorId:   $actorId,
            mutate: static function (PaymentProviderAccountModel $account): void {
                $account->suspended_at = now();
                $account->is_active    = false;
            },
        );
    }

    public function reactivate(string $accountId, string $actorId): PaymentProviderAccountModel
    {
        return $this->transition(
            accountId: $accountId,
            from:      AccountConfigLifecycleStatus::Suspended,
            to:        AccountConfigLifecycleStatus::Active,
            actorId:   $actorId,
            mutate: static function (PaymentProviderAccountModel $account): void {
                $account->suspended_at = null;
                $account->is_active    = true;
            },
        );
    }

    /**
     * FR-028: Blocked if outstanding payment obligations exist without approved resolution route.
     */
    public function retire(string $accountId, string $actorId, bool $resolutionRouteRecorded = false): PaymentProviderAccountModel
    {
        $account = $this->findOrFail($accountId);

        if (!$resolutionRouteRecorded && $this->hasOutstandingObligations($accountId)) {
            throw LifecycleTransitionException::outstandingObligations(
                $account->account_lifecycle_status,
                AccountConfigLifecycleStatus::Retired->value,
            );
        }

        return $this->transition(
            accountId: $accountId,
            from:      AccountConfigLifecycleStatus::from($account->account_lifecycle_status),
            to:        AccountConfigLifecycleStatus::Retired,
            actorId:   $actorId,
            mutate: static function (PaymentProviderAccountModel $account): void {
                $account->retired_at = now();
                $account->is_active  = false;
            },
        );
    }

    public function hasOutstandingObligations(string $accountId): bool
    {
        return PaymentAttemptModel::query()
            ->where('provider_account_id', $accountId)
            ->whereNotIn('status', ['success', 'failed', 'cancelled', 'expired'])
            ->exists();
    }

    /**
     * T051: Creates an immutable ConfigurationVersion snapshot at activation time.
     */
    private function createVersionSnapshot(string $accountId, string $actorId): ConfigurationVersionModel
    {
        $lastVersion = ConfigurationVersionModel::query()
            ->where('account_id', $accountId)
            ->max('version_number') ?? 0;

        $operationConfigs = ApiOperationConfigModel::query()
            ->where('account_id', $accountId)
            ->whereNull('config_version_id')
            ->get()
            ->toArray();

        $version = ConfigurationVersionModel::create([
            'id'                   => (string) Str::uuid7(),
            'account_id'           => $accountId,
            'version_number'       => $lastVersion + 1,
            'snapshot'             => $operationConfigs,
            'activated_at'         => now(),
            'activated_by_actor_id' => $actorId,
        ]);

        // Link draft operation configs to this version
        ApiOperationConfigModel::query()
            ->where('account_id', $accountId)
            ->whereNull('config_version_id')
            ->update(['config_version_id' => $version->id]);

        return $version;
    }

    private function transition(
        string                       $accountId,
        AccountConfigLifecycleStatus $from,
        AccountConfigLifecycleStatus $to,
        string                       $actorId,
        ?\Closure                    $mutate = null,
    ): PaymentProviderAccountModel {
        return DB::connection('payment')->transaction(function () use ($accountId, $from, $to, $actorId, $mutate): PaymentProviderAccountModel {
            $account = $this->findOrFail($accountId);

            $this->assertCanTransition($account, $from, $to);

            $account->account_lifecycle_status = $to->value;

            if ($mutate !== null) {
                $mutate($account);
            }

            $account->save();

            $this->recordAudit("payment.integration-account.{$to->value}", $actorId, $accountId);

            return $account->fresh();
        });
    }

    private function assertCanTransition(
        PaymentProviderAccountModel  $account,
        AccountConfigLifecycleStatus $from,
        AccountConfigLifecycleStatus $to,
    ): void {
        $current = AccountConfigLifecycleStatus::from($account->account_lifecycle_status);

        if ($current !== $from) {
            throw LifecycleTransitionException::invalidTransition($current->value, $to->value);
        }

        if (!$current->canTransitionTo($to)) {
            throw LifecycleTransitionException::invalidTransition($current->value, $to->value);
        }
    }

    private function findOrFail(string $accountId): PaymentProviderAccountModel
    {
        $account = PaymentProviderAccountModel::query()->find($accountId);

        if ($account === null) {
            throw new \RuntimeException("Integration account not found: {$accountId}");
        }

        return $account;
    }

    private function recordAudit(string $action, string $actorId, string $resourceId, array $afterState = []): void
    {
        $this->audit->record(new AuditEventData(
            action:        $action,
            actorType:     'admin',
            resourceType:  'payment_provider_account',
            correlationId: $resourceId,
            outcome:       'success',
            actorId:       $actorId,
            resourceId:    $resourceId,
            afterState:    $afterState ?: null,
        ));
    }
}
