<?php

declare(strict_types=1);

namespace App\Modules\Profile\Domain\Services;

use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Profile\Application\Commands\CancelClosureCommand;
use App\Modules\Profile\Application\Commands\PermanentlyCloseAccountCommand;
use App\Modules\Profile\Application\Commands\ReactivateAccountCommand;
use App\Modules\Profile\Application\Commands\RequestClosureCommand;
use App\Modules\Profile\Application\Commands\SuspendAccountCommand;
use App\Modules\Profile\Domain\Events\AccountClosedEvent;
use App\Modules\Profile\Domain\Events\AccountClosureBlockedEvent;
use App\Modules\Profile\Domain\Events\AccountClosureCancelledEvent;
use App\Modules\Profile\Domain\Events\AccountClosureRequestedEvent;
use App\Modules\Profile\Domain\Events\AccountReactivatedEvent;
use App\Modules\Profile\Domain\Events\AccountSuspendedEvent;
use App\Modules\Profile\Domain\Exceptions\ClosureBlockedByObligationsException;
use App\Modules\Profile\Domain\Exceptions\InvalidStatusTransitionException;
use App\Modules\Profile\Domain\Exceptions\ProfileNotFoundException;
use App\Modules\Profile\Domain\ValueObjects\AccountStatus;
use App\Modules\Profile\Infrastructure\Repositories\EloquentAccountStatusHistoryRepository;
use App\Modules\Profile\Infrastructure\Repositories\EloquentUserProfileRepository;
use Illuminate\Support\Facades\DB;

final class AccountStatusService
{
    public function __construct(
        private readonly EloquentUserProfileRepository          $profiles,
        private readonly EloquentAccountStatusHistoryRepository $history,
        private readonly EventPublisherInterface                $events,
        private readonly AuditServiceInterface                  $audit,
        private readonly ProfileMetrics                        $metrics,
        private readonly ClosureGateService                    $closureGate,
    ) {}

    /**
     * @throws ProfileNotFoundException
     * @throws \App\Modules\Profile\Domain\Exceptions\InvalidStatusTransitionException
     */
    public function suspend(SuspendAccountCommand $command): void
    {
        $profile = $this->profiles->findByUserId($command->targetUserId);
        if ($profile === null) {
            throw new ProfileNotFoundException("Profile not found for user {$command->targetUserId}");
        }

        $current = AccountStatus::from($profile->account_status);
        $current->assertCanTransitionTo(AccountStatus::SUSPENDED);

        DB::connection('profile')->transaction(function () use ($command, $profile): void {
            $this->profiles->update($command->targetUserId, [
                'account_status' => AccountStatus::SUSPENDED->value,
            ]);

            $this->history->appendTransition([
                'user_id'        => $command->targetUserId,
                'from_status'    => $profile->account_status,
                'to_status'      => AccountStatus::SUSPENDED->value,
                'reason'         => $command->reason,
                'actor_id'       => $command->actorId,
                'actor_type'     => 'ADMIN',
                'correlation_id' => $command->correlationId,
            ]);

            $this->events->publish(new AccountSuspendedEvent(
                userId:        $command->targetUserId,
                actorId:       $command->actorId,
                correlationId: $command->correlationId,
                reason:        $command->reason,
            ));
        });

        $this->audit->record(new AuditEventData(
            action:        'profile.account.suspended',
            actorType:     'ADMIN',
            resourceType:  'UserProfile',
            correlationId: $command->correlationId,
            outcome:       'success',
            actorId:       $command->actorId,
            resourceId:    $command->targetUserId,
            metadata:      [
                'subject_user_id' => $command->targetUserId,
                'reason'          => $command->reason,
            ],
        ));

        $this->metrics->accountStatusTransition($current->value, AccountStatus::SUSPENDED->value);
    }

    /**
     * @throws ProfileNotFoundException
     * @throws \App\Modules\Profile\Domain\Exceptions\InvalidStatusTransitionException
     */
    public function reactivate(ReactivateAccountCommand $command): void
    {
        $profile = $this->profiles->findByUserId($command->targetUserId);
        if ($profile === null) {
            throw new ProfileNotFoundException("Profile not found for user {$command->targetUserId}");
        }

        $current = AccountStatus::from($profile->account_status);
        $current->assertCanTransitionTo(AccountStatus::ACTIVE);

        DB::connection('profile')->transaction(function () use ($command, $profile): void {
            $this->profiles->update($command->targetUserId, [
                'account_status' => AccountStatus::ACTIVE->value,
            ]);

            $this->history->appendTransition([
                'user_id'        => $command->targetUserId,
                'from_status'    => $profile->account_status,
                'to_status'      => AccountStatus::ACTIVE->value,
                'reason'         => $command->reason,
                'actor_id'       => $command->actorId,
                'actor_type'     => 'ADMIN',
                'correlation_id' => $command->correlationId,
            ]);

            $this->events->publish(new AccountReactivatedEvent(
                userId:        $command->targetUserId,
                actorId:       $command->actorId,
                correlationId: $command->correlationId,
                reason:        $command->reason,
            ));
        });

        $this->audit->record(new AuditEventData(
            action:        'profile.account.reactivated',
            actorType:     'ADMIN',
            resourceType:  'UserProfile',
            correlationId: $command->correlationId,
            outcome:       'success',
            actorId:       $command->actorId,
            resourceId:    $command->targetUserId,
            metadata:      [
                'subject_user_id' => $command->targetUserId,
                'reason'          => $command->reason,
            ],
        ));

        $this->metrics->accountStatusTransition($current->value, AccountStatus::ACTIVE->value);
    }

    /**
     * Request account closure (user self-service).
     * Checks financial obligations via ClosureGateService before transitioning to pending_closure.
     * If blocked: emits ClosureBlocked event, audits, increments metric, re-throws.
     *
     * @throws ProfileNotFoundException
     * @throws \App\Modules\Profile\Domain\Exceptions\InvalidStatusTransitionException
     * @throws ClosureBlockedByObligationsException
     */
    public function requestClosure(RequestClosureCommand $command): void
    {
        $profile = $this->profiles->findByUserId($command->userId);
        if ($profile === null) {
            throw new ProfileNotFoundException("Profile not found for user {$command->userId}");
        }

        $current = AccountStatus::from($profile->account_status);

        try {
            $this->closureGate->checkAndEnforce($command->userId);
        } catch (ClosureBlockedByObligationsException $e) {
            // Write a history row recording the blocked attempt; no status transition occurs
            $this->history->appendTransition([
                'user_id'              => $command->userId,
                'from_status'          => $current->value,
                'to_status'            => $current->value,
                'reason'               => null,
                'actor_id'             => $command->actorId,
                'actor_type'           => $command->actorType,
                'closure_obligations'  => $e->blockedBy,
                'correlation_id'       => $command->correlationId,
            ]);

            $this->events->publish(new AccountClosureBlockedEvent(
                userId:        $command->userId,
                actorId:       $command->actorId,
                correlationId: $command->correlationId,
                blockedBy:     $e->blockedBy,
            ));

            $this->audit->record(new AuditEventData(
                action:        'profile.account.closure-blocked',
                actorType:     $command->actorType,
                resourceType:  'UserProfile',
                correlationId: $command->correlationId,
                outcome:       'blocked',
                actorId:       $command->actorId,
                resourceId:    $command->userId,
                metadata:      ['blocked_by' => $e->blockedBy],
            ));

            $this->metrics->closureBlocked($e->blockedBy[0] ?? 'unknown');

            throw $e;
        }

        $current->assertCanTransitionTo(AccountStatus::PENDING_CLOSURE);

        DB::connection('profile')->transaction(function () use ($command, $profile): void {
            $this->profiles->update($command->userId, [
                'account_status' => AccountStatus::PENDING_CLOSURE->value,
            ]);

            $this->history->appendTransition([
                'user_id'        => $command->userId,
                'from_status'    => $profile->account_status,
                'to_status'      => AccountStatus::PENDING_CLOSURE->value,
                'reason'         => null,
                'actor_id'       => $command->actorId,
                'actor_type'     => $command->actorType,
                'correlation_id' => $command->correlationId,
            ]);

            $this->events->publish(new AccountClosureRequestedEvent(
                userId:        $command->userId,
                actorId:       $command->actorId,
                correlationId: $command->correlationId,
                actorType:     $command->actorType,
            ));
        });

        $this->audit->record(new AuditEventData(
            action:        'profile.account.closure-requested',
            actorType:     $command->actorType,
            resourceType:  'UserProfile',
            correlationId: $command->correlationId,
            outcome:       'success',
            actorId:       $command->actorId,
            resourceId:    $command->userId,
            metadata:      [],
        ));

        $this->metrics->accountStatusTransition($current->value, AccountStatus::PENDING_CLOSURE->value);
    }

    /**
     * Cancel pending closure. User or admin can cancel.
     * Validates current status is pending_closure, transitions to active.
     *
     * @throws ProfileNotFoundException
     * @throws \App\Modules\Profile\Domain\Exceptions\InvalidStatusTransitionException
     */
    public function cancelClosure(CancelClosureCommand $command): void
    {
        $profile = $this->profiles->findByUserId($command->userId);
        if ($profile === null) {
            throw new ProfileNotFoundException("Profile not found for user {$command->userId}");
        }

        $current = AccountStatus::from($profile->account_status);

        if ($current !== AccountStatus::PENDING_CLOSURE) {
            throw new InvalidStatusTransitionException(
                "Cannot cancel closure: current status is '{$current->value}', expected 'pending_closure'."
            );
        }

        DB::connection('profile')->transaction(function () use ($command, $profile): void {
            $this->profiles->update($command->userId, [
                'account_status' => AccountStatus::ACTIVE->value,
            ]);

            $this->history->appendTransition([
                'user_id'        => $command->userId,
                'from_status'    => $profile->account_status,
                'to_status'      => AccountStatus::ACTIVE->value,
                'reason'         => $command->reason,
                'actor_id'       => $command->actorId,
                'actor_type'     => $command->actorType,
                'correlation_id' => $command->correlationId,
            ]);

            $this->events->publish(new AccountClosureCancelledEvent(
                userId:        $command->userId,
                actorId:       $command->actorId,
                correlationId: $command->correlationId,
                actorType:     $command->actorType,
            ));
        });

        $this->audit->record(new AuditEventData(
            action:        'profile.account.closure-cancelled',
            actorType:     $command->actorType,
            resourceType:  'UserProfile',
            correlationId: $command->correlationId,
            outcome:       'success',
            actorId:       $command->actorId,
            resourceId:    $command->userId,
            metadata:      [
                'subject_user_id' => $command->userId,
            ],
        ));

        $this->metrics->accountStatusTransition($current->value, AccountStatus::ACTIVE->value);
    }

    /**
     * Permanently close an account. Admin-only; `closed` is terminal.
     * Performs a final obligation check even on the admin path.
     *
     * @throws ProfileNotFoundException
     * @throws \App\Modules\Profile\Domain\Exceptions\InvalidStatusTransitionException
     * @throws ClosureBlockedByObligationsException
     */
    public function permanentlyClose(PermanentlyCloseAccountCommand $command): void
    {
        $profile = $this->profiles->findByUserId($command->targetUserId);
        if ($profile === null) {
            throw new ProfileNotFoundException("Profile not found for user {$command->targetUserId}");
        }

        $current = AccountStatus::from($profile->account_status);

        if ($current !== AccountStatus::PENDING_CLOSURE) {
            throw new InvalidStatusTransitionException(
                "Cannot permanently close: current status is '{$current->value}', expected 'pending_closure'."
            );
        }

        // Final obligation check — admin cannot bypass the gate (FR-E02-037)
        try {
            $this->closureGate->checkAndEnforce($command->targetUserId);
        } catch (ClosureBlockedByObligationsException $e) {
            // Write a history row recording the blocked attempt; no status transition occurs
            $this->history->appendTransition([
                'user_id'              => $command->targetUserId,
                'from_status'          => $current->value,
                'to_status'            => $current->value,
                'reason'               => null,
                'actor_id'             => $command->actorId,
                'actor_type'           => 'ADMIN',
                'closure_obligations'  => $e->blockedBy,
                'correlation_id'       => $command->correlationId,
            ]);

            $this->events->publish(new AccountClosureBlockedEvent(
                userId:        $command->targetUserId,
                actorId:       $command->actorId,
                correlationId: $command->correlationId,
                blockedBy:     $e->blockedBy,
            ));

            $this->audit->record(new AuditEventData(
                action:        'profile.account.closure-blocked',
                actorType:     'ADMIN',
                resourceType:  'UserProfile',
                correlationId: $command->correlationId,
                outcome:       'blocked',
                actorId:       $command->actorId,
                resourceId:    $command->targetUserId,
                metadata:      [
                    'subject_user_id' => $command->targetUserId,
                    'blocked_by'      => $e->blockedBy,
                ],
            ));

            $this->metrics->closureBlocked($e->blockedBy[0] ?? 'unknown');

            throw $e;
        }

        DB::connection('profile')->transaction(function () use ($command, $profile): void {
            $this->profiles->update($command->targetUserId, [
                'account_status' => AccountStatus::CLOSED->value,
            ]);

            $this->history->appendTransition([
                'user_id'        => $command->targetUserId,
                'from_status'    => $profile->account_status,
                'to_status'      => AccountStatus::CLOSED->value,
                'reason'         => $command->reason,
                'actor_id'       => $command->actorId,
                'actor_type'     => 'ADMIN',
                'correlation_id' => $command->correlationId,
            ]);

            $this->events->publish(new AccountClosedEvent(
                userId:        $command->targetUserId,
                actorId:       $command->actorId,
                correlationId: $command->correlationId,
            ));
        });

        $this->audit->record(new AuditEventData(
            action:        'profile.account.closed',
            actorType:     'ADMIN',
            resourceType:  'UserProfile',
            correlationId: $command->correlationId,
            outcome:       'success',
            actorId:       $command->actorId,
            resourceId:    $command->targetUserId,
            metadata:      [
                'subject_user_id' => $command->targetUserId,
                'reason'          => $command->reason,
            ],
        ));

        $this->metrics->accountStatusTransition($current->value, AccountStatus::CLOSED->value);
    }
}
