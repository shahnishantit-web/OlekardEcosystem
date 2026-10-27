<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Services;

use App\Modules\Integration\Infrastructure\Models\WebhookEventModel;
use App\Modules\Payment\Domain\Contracts\PaymentOutcomeProcessor;
use App\Modules\Payment\Infrastructure\Models\PaymentAttemptModel;
use App\Modules\Payment\Infrastructure\Models\PaymentIntentModel;
use App\Modules\Payment\Infrastructure\Models\PaymentProviderAccountModel;

final class PaymentWebhookHandler
{
    public function __construct(
        private readonly PaymentNotificationValidator $validator,
        private readonly PaymentOutcomeProcessor $orchestration,
    ) {}

    /** Returns a safe quarantine reason; E25 owns receipt persistence and auditing. */
    public function handle(WebhookEventModel $receipt): ?string
    {
        $notification = $receipt->verified_notification;
        if (! is_array($notification) || $receipt->payload_hash === null || $receipt->encrypted_evidence === null) {
            return 'unverified_receipt';
        }
        if (! hash_equals($receipt->payload_hash, hash('sha256', $receipt->encrypted_evidence))) {
            return 'evidence_integrity';
        }
        $entityId = $notification['entity_id'] ?? null;
        if (! is_string($entityId) || ! \Ramsey\Uuid\Uuid::isValid($entityId)) {
            return 'unresolved_mapping';
        }
        $attempt = PaymentAttemptModel::find($entityId);
        $intent = $attempt !== null ? PaymentIntentModel::find($attempt->payment_intent_id) : null;
        $account = $attempt !== null ? PaymentProviderAccountModel::with('provider')->find($attempt->provider_account_id) : null;
        if ($attempt === null || $intent === null || $account === null) {
            return 'unresolved_mapping';
        }
        $error = $this->validator->validate($notification, $attempt, $intent, $account);
        if ($error !== null) {
            return $error;
        }
        $testOnly = ($notification['context']['test_only'] ?? false) === true;
        if (($testOnly && ! app()->environment('local', 'development', 'testing'))
            || (! $testOnly && ! config('payment.live_callback_processing_enabled', false))) {
            return 'activation_blocked';
        }
        // Existing P06/P07 orchestration owns locks, operation deduplication,
        // contradictory evidence and P08 accounting. Never write balances here.
        $this->orchestration->handleOutcome([
            'payment_intent_id' => $intent->id,
            'payment_attempt_id' => $attempt->id,
            'outcome' => $notification['status'] === 'success' ? 'succeeded' : 'failed',
            'provider_reference_id' => $notification['provider_reference'],
            'captured_amount_minor' => $intent->amount_minor,
            'fee_minor' => $attempt->fee_minor ?? 0,
            'correlation_id' => $notification['correlation_id'],
        ]);
        return null;
    }
}
