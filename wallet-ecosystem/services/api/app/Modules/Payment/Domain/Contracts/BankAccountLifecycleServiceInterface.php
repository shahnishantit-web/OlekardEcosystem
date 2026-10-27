<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Contracts;

use App\Modules\Payment\Infrastructure\Models\PaymentProviderAccountModel;

interface BankAccountLifecycleServiceInterface
{
    public function submitForValidation(string $accountId, string $actorId): PaymentProviderAccountModel;

    public function initiateSandboxTesting(string $accountId, string $actorId): PaymentProviderAccountModel;

    public function submitEvidence(string $accountId, string $actorId): PaymentProviderAccountModel;

    /**
     * FR-013: approverActorId MUST differ from the submitted_by_actor_id on the account.
     *
     * @throws \App\Modules\Payment\Domain\Exceptions\ApprovalConflictException
     */
    public function approve(string $accountId, string $approverActorId): PaymentProviderAccountModel;

    public function reject(string $accountId, string $reason, string $actorId): PaymentProviderAccountModel;

    public function requestChanges(string $accountId, string $reason, string $actorId): PaymentProviderAccountModel;

    /**
     * Creates an immutable ConfigurationVersion snapshot and sets active_config_version_id.
     */
    public function activate(string $accountId, string $actorId): PaymentProviderAccountModel;

    public function suspend(string $accountId, string $actorId): PaymentProviderAccountModel;

    public function reactivate(string $accountId, string $actorId): PaymentProviderAccountModel;

    /**
     * FR-028: Blocked if outstanding payment obligations exist without an approved resolution route.
     */
    public function retire(string $accountId, string $actorId, bool $resolutionRouteRecorded = false): PaymentProviderAccountModel;
}
