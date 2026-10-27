<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Services;

use App\Modules\Payment\Infrastructure\Models\PaymentAttemptModel;
use App\Modules\Payment\Infrastructure\Models\PaymentIntentModel;
use App\Modules\Payment\Infrastructure\Models\PaymentProviderAccountModel;

final class PaymentNotificationValidator
{
    /** No financial effects; safe codes only, never provider payload in exceptions. */
    public function validate(array $notification, PaymentAttemptModel $attempt, PaymentIntentModel $intent, PaymentProviderAccountModel $account): ?string
    {
        $context = $notification['context'] ?? [];
        if (($notification['entity_id'] ?? null) !== $attempt->id || $attempt->payment_intent_id !== $intent->id) {
            return 'attempt_mismatch';
        }
        if (($context['payment_account_id'] ?? null) !== $attempt->provider_account_id
            || $account->id !== $attempt->provider_account_id || $account->provider === null
            || ($context['payment_provider_code'] ?? null) !== $attempt->provider_code
            || $account->provider->code !== $attempt->provider_code
            || ($context['payment_environment'] ?? null) !== $account->provider->environment
            || ($context['merchant_account'] ?? null) !== $account->merchant_account_reference) {
            return 'account_mismatch';
        }
        if (($context['amount_minor'] ?? null) !== $intent->amount_minor
            || ($context['currency'] ?? null) !== $intent->currency_code) {
            return 'money_mismatch';
        }
        if (! is_string($notification['provider_reference'] ?? null)
            || $notification['provider_reference'] === ''
            || ($attempt->provider_reference_id !== null && $attempt->provider_reference_id !== $notification['provider_reference'])) {
            return 'reference_mismatch';
        }
        if (! in_array($notification['status'] ?? null, ['success', 'failure'], true)) {
            return 'unresolved_outcome';
        }
        return null;
    }
}
