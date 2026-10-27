<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Enums;

enum PayoutRequestStatus: string
{
    case PendingVerification = 'pending_verification';
    case AwaitingApproval    = 'awaiting_approval';
    case Reserved            = 'reserved';
    case Approved            = 'approved';
    case Executing           = 'executing';
    case PendingSettlement   = 'pending_settlement';
    case Settled             = 'settled';
    case Failed              = 'failed';
    case ManualReview        = 'manual_review';
    case Cancelled           = 'cancelled';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Settled, self::Failed, self::Cancelled => true,
            default => false,
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), strict: true);
    }

    /** @return array<self> */
    private function allowedTransitions(): array
    {
        return match ($this) {
            self::PendingVerification => [self::AwaitingApproval, self::Cancelled],
            self::AwaitingApproval    => [self::Reserved, self::Cancelled],
            self::Reserved            => [self::Approved, self::Cancelled],
            self::Approved            => [self::Executing, self::Cancelled],
            self::Executing           => [self::PendingSettlement, self::Settled, self::Failed, self::ManualReview],
            self::PendingSettlement   => [self::Settled, self::ManualReview],
            self::Failed              => [self::AwaitingApproval],
            self::ManualReview        => [self::AwaitingApproval],
            self::Settled, self::Cancelled => [],
        };
    }
}
