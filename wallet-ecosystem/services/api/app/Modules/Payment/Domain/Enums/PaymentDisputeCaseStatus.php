<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Enums;

enum PaymentDisputeCaseStatus: string
{
    case Received             = 'received';
    case UnderReview          = 'under_review';
    case Won                  = 'won';
    case Lost                 = 'lost';
    case ClosedReversed       = 'closed_reversed';
    case InvestigationRequired = 'investigation_required';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Won, self::Lost, self::ClosedReversed => true,
            default                                      => false,
        };
    }

    public function allowsFinancialActions(): bool
    {
        return match ($this) {
            self::Received, self::UnderReview, self::InvestigationRequired => true,
            default                                                          => false,
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Received              => $target === self::UnderReview,
            self::UnderReview           => in_array($target, [
                self::Won,
                self::Lost,
                self::ClosedReversed,
                self::InvestigationRequired,
            ], true),
            self::InvestigationRequired => in_array($target, [
                self::Won,
                self::Lost,
                self::ClosedReversed,
                self::InvestigationRequired,
            ], true),
            default => false,
        };
    }

    public function consumerLabel(): string
    {
        return match ($this) {
            self::Received              => 'Under investigation',
            self::UnderReview           => 'Under investigation',
            self::Won                   => 'Resolved in your favour',
            self::Lost                  => 'Resolved',
            self::ClosedReversed        => 'Payment reversed',
            self::InvestigationRequired => 'Further review required',
        };
    }
}
