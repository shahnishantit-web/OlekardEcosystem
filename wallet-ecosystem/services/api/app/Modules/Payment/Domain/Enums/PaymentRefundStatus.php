<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Enums;

enum PaymentRefundStatus: string
{
    // Initial momentary state — written then immediately transitioned in the same DB transaction (P15 Decision 5)
    case Requested        = 'requested';
    case PendingApproval  = 'pending_approval';
    case Processing       = 'processing';
    // P15 async bank states
    case Submitted        = 'submitted';
    case Pending          = 'pending';
    case Unknown          = 'unknown';
    // Terminal states
    case Confirmed        = 'confirmed';        // New terminal — use for all new refunds
    case DefinitiveFailure = 'definitive_failure'; // Provider permanently declined
    case Succeeded        = 'succeeded';        // Legacy terminal — keep for backward compat
    case Failed           = 'failed';
    case Cancelled        = 'cancelled';
    case Rejected         = 'rejected';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Confirmed,
            self::DefinitiveFailure,
            self::Succeeded,
            self::Failed,
            self::Cancelled,
            self::Rejected  => true,
            default         => false,
        };
    }

    /**
     * Returns true if this refund is in a non-terminal state whose amount is still reserved.
     * Used by sumReservedRefundsForAttempt() to determine which statuses lock funds.
     */
    public function reservesFunds(): bool
    {
        return ! $this->isTerminal();
    }

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Requested        => in_array($target, [self::PendingApproval, self::Processing, self::Cancelled], true),
            self::PendingApproval  => in_array($target, [self::Processing, self::Rejected, self::Cancelled], true),
            self::Processing       => in_array($target, [self::Submitted, self::Confirmed, self::Failed, self::Cancelled], true),
            self::Submitted        => in_array($target, [self::Pending, self::Confirmed, self::Unknown, self::DefinitiveFailure], true),
            self::Pending          => in_array($target, [self::Confirmed, self::DefinitiveFailure, self::Unknown], true),
            self::Unknown          => in_array($target, [self::Confirmed, self::DefinitiveFailure], true),
            default                => false, // all terminal states have no valid targets
        };
    }

    /** Human-readable label safe to show to a consumer in the mobile app. */
    public function consumerLabel(): string
    {
        return match ($this) {
            self::Requested, self::PendingApproval => 'Awaiting review',
            self::Processing                        => 'Being processed',
            self::Submitted, self::Pending          => 'Being processed',
            self::Unknown                           => 'Under review',
            self::Confirmed, self::Succeeded        => 'Funds returned',
            self::DefinitiveFailure, self::Failed   => 'Refund declined',
            self::Cancelled                         => 'Cancelled',
            self::Rejected                          => 'Request declined',
        };
    }

    /** True only when the refund is in a confirmed/succeeded terminal state. */
    public function fundsReturned(): bool
    {
        return $this === self::Confirmed || $this === self::Succeeded;
    }
}
