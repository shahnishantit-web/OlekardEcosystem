<?php

declare(strict_types=1);

namespace App\Modules\AdminApp\Application\Services;

use App\Modules\AdminApp\Domain\Models\PendingAdminAction;
use App\Modules\Agent\Application\Services\AgentLifecycleService;
use App\Modules\Kyc\Application\Services\KycCaseService;
use App\Modules\Wallet\Application\Services\WalletService;

final class AdminActionExecutorService
{
    public function __construct(
        private readonly KycCaseService $kycCaseService,
        private readonly WalletService $walletService,
        private readonly AgentLifecycleService $agentLifecycleService,
    ) {}

    /**
     * Execute the domain action for an approved pending admin action.
     * Called by ApprovalController after PendingAdminActionService::approve() succeeds.
     */
    public function execute(PendingAdminAction $action, string $checkerId, string $correlationId): void
    {
        $payload = $action->payload ?? [];

        match ($action->action_type) {
            'adminapp.kyc.approve' => $this->kycCaseService->approveCase(
                caseId:        $action->resource_id,
                reviewerId:    $checkerId,
                correlationId: $correlationId,
                actorType:     'ADMIN',
            ),
            'adminapp.kyc.reject' => $this->kycCaseService->rejectCase(
                caseId:        $action->resource_id,
                reviewerId:    $checkerId,
                correlationId: $correlationId,
                reason:        $payload['reason'] ?? 'Rejected by admin',
                actorType:     'ADMIN',
            ),
            'adminapp.wallet.freeze' => $this->walletService->freeze(
                walletId:      $action->resource_id,
                reason:        $payload['reason'] ?? '',
                actorId:       $checkerId,
                correlationId: $correlationId,
            ),
            'adminapp.wallet.unfreeze' => $this->walletService->unfreeze(
                walletId:      $action->resource_id,
                reason:        $payload['reason'] ?? '',
                actorId:       $checkerId,
                correlationId: $correlationId,
            ),
            'adminapp.transaction.reverse' => $this->reverseTransaction(
                transactionId: $action->resource_id,
                reason:        $payload['reason'] ?? '',
                actorId:       $checkerId,
                correlationId: $correlationId,
            ),
            'adminapp.agent.approve' => $this->agentLifecycleService->approve(
                applicationId:  $action->resource_id,
                adminActorId:   $checkerId,
                notes:          $payload['notes'] ?? null,
                idempotencyKey: 'admin_approve:' . $action->resource_id . ':' . $checkerId,
                correlationId:  $correlationId,
            ),
            default => throw new \DomainException("Unknown action type: {$action->action_type}"),
        };
    }

    private function reverseTransaction(
        string $transactionId,
        string $reason,
        string $actorId,
        string $correlationId,
    ): void {
        // Transaction reversal is handled by Transfer/Transaction modules via ReversalService (E09/E10).
        // @phpstan-ignore-next-line
        $reversalService = app(\App\Modules\Wallet\Application\Services\ReversalService::class);
        // @phpstan-ignore-next-line
        $reversalService->reverseByAdmin($transactionId, $reason, $actorId, $correlationId);
    }
}
