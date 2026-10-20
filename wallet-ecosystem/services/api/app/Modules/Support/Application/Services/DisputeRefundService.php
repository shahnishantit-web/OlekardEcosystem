<?php

declare(strict_types=1);

namespace App\Modules\Support\Application\Services;

use App\Modules\Support\Infrastructure\Models\DisputeModel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Compatibility facade: original E22 API shares the hardened financial transaction. */
final class DisputeRefundService
{
    public function __construct(private readonly AdminDisputeRefundService $refunds) {}

    public function initiateRefund(string $disputeId, string $initiatedById, string $actorRole, int $amountMinor, string $currency, ?string $partialRefundJustification, string $idempotencyKey, string $correlationId, string $stepUpToken = '', string $sessionId = ''): DisputeModel
    {
        abort_unless(strtolower($actorRole) === 'admin', 403);
        try {
            $this->refunds->refund($disputeId, ['amount_minor' => $amountMinor, 'reason' => $partialRefundJustification ?? 'Approved dispute refund', 'currency' => $currency, 'step_up_token' => $stepUpToken], $initiatedById, $sessionId, $idempotencyKey, $correlationId);
        } catch (HttpExceptionInterface $e) {
            if ($e->getStatusCode() === 409) {
                abort(422, $e->getMessage());
            } throw $e;
        }

        return DisputeModel::findOrFail($disputeId);
    }

    public function confirmRefund(string $disputeId, string $confirmedById, string $actorRole, string $idempotencyKey, string $correlationId, string $stepUpToken = '', string $sessionId = ''): DisputeModel
    {
        abort_unless(strtolower($actorRole) === 'admin', 403);

        return DB::connection('platform')->transaction(function () use ($disputeId, $confirmedById, $idempotencyKey, $correlationId, $stepUpToken, $sessionId): DisputeModel {
            $d = DisputeModel::lockForUpdate()->findOrFail($disputeId);
            try {
                $this->refunds->refund($disputeId, ['amount_minor' => $d->refunded_amount_minor, 'reason' => $d->partial_refund_justification, 'currency' => $d->currency_code, 'step_up_token' => $stepUpToken, 'confirm' => true], $confirmedById, $sessionId, $idempotencyKey, $correlationId);
            } catch (HttpExceptionInterface $e) {
                if ($e->getStatusCode() === 409) {
                    abort($d->refund_initiator_id === $confirmedById ? 403 : 422, $e->getMessage());
                } throw $e;
            }

            return $d->fresh();
        });
    }
}
