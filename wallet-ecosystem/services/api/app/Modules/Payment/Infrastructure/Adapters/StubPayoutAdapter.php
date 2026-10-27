<?php

declare(strict_types=1);

// DEV MOCK ONLY — bound only in local/development/testing environments.
// See PaymentServiceProvider. Never bind in production.

namespace App\Modules\Payment\Infrastructure\Adapters;

use App\Modules\Payment\Domain\Contracts\PayoutAdapterInterface;

final class StubPayoutAdapter implements PayoutAdapterInterface
{
    // AD-14 pending: this stub satisfies the interface contract so PaymentServiceProvider
    // can bind 'payment.payout.adapter.stub' without triggering a class-not-found error.
    // All payout execution methods are intentionally absent until AD-14 is resolved.
}
