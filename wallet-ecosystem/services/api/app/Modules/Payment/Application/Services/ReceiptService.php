<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Services;

use App\Exceptions\ResourceNotFoundException;
use App\Modules\Payment\Domain\Enums\PaymentAttemptStatus;
use App\Modules\Payment\Domain\Enums\PaymentIntentStatus;
use App\Modules\Payment\Domain\ValueObjects\ReceiptRecord;
use App\Modules\Payment\Infrastructure\Models\PaymentAttemptModel;
use App\Modules\Payment\Infrastructure\Models\PaymentIntentModel;
use App\Modules\Payment\Infrastructure\Models\PaymentRefundModel;
use Illuminate\Auth\Access\AuthorizationException;

final class ReceiptService
{
    /**
     * Compile an immutable ReceiptRecord for a confirmed payment intent.
     *
     * Ownership is enforced: only the original payer may access the receipt.
     * For admin access (bypass ownership check) use compileForAdmin().
     *
     * @throws ResourceNotFoundException if the intent is not found or not in a confirmed state
     * @throws AuthorizationException    if the requestor is not the intent's payer
     */
    public function compile(string $intentId, string $requestorId): ReceiptRecord
    {
        $intent = PaymentIntentModel::find($intentId);

        if ($intent === null) {
            // Return 404 not 403 to avoid leaking existence of cross-payer resources (FR-008)
            throw new ResourceNotFoundException('PaymentIntent', $intentId);
        }

        if ($intent->payer_actor_id !== $requestorId) {
            throw new ResourceNotFoundException('PaymentIntent', $intentId);
        }

        return $this->compileConfirmedIntent($intent);
    }

    /**
     * Compile a ReceiptRecord for admin access (bypasses ownership check).
     *
     * @throws ResourceNotFoundException if the intent is not found or not in a confirmed state
     */
    public function compileForAdmin(string $intentId): ReceiptRecord
    {
        $intent = PaymentIntentModel::find($intentId);

        if ($intent === null) {
            throw new ResourceNotFoundException('PaymentIntent', $intentId);
        }

        return $this->compileConfirmedIntent($intent);
    }

    private function compileConfirmedIntent(PaymentIntentModel $intent): ReceiptRecord
    {
        // Only confirmed (succeeded) intents get a receipt; failed intents return 404 (FR-008)
        if ($intent->status !== PaymentIntentStatus::Succeeded) {
            throw new ResourceNotFoundException('Receipt', $intent->id);
        }

        $attempt = PaymentAttemptModel::where('payment_intent_id', $intent->id)
            ->where('status', PaymentAttemptStatus::Succeeded)
            ->orderByDesc('confirmed_at')
            ->first();

        if ($attempt === null) {
            throw new ResourceNotFoundException('Receipt', $intent->id);
        }

        $refunds = PaymentRefundModel::where('payment_intent_id', $intent->id)
            ->whereNotIn('status', ['failed', 'cancelled', 'rejected', 'definitive_failure'])
            ->orderBy('created_at')
            ->get()
            ->all();

        return ReceiptRecord::fromModels($intent, $attempt, $refunds);
    }
}
