<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Exceptions;

use DomainException;

final class LifecycleTransitionException extends DomainException
{
    public function __construct(
        public readonly string $fromStatus,
        public readonly string $toStatus,
        public readonly string $reason,
    ) {
        parent::__construct(
            sprintf(
                'Lifecycle transition from "%s" to "%s" is not allowed: %s',
                $fromStatus,
                $toStatus,
                $reason,
            )
        );
    }

    public static function invalidTransition(string $from, string $to): self
    {
        return new self($from, $to, sprintf('transition from "%s" to "%s" is not a valid lifecycle step', $from, $to));
    }

    public static function missingRequiredEvidence(string $from, string $to): self
    {
        return new self($from, $to, 'missing_required_evidence');
    }

    public static function outstandingObligations(string $from, string $to): self
    {
        return new self($from, $to, 'outstanding_obligations');
    }
}
