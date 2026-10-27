<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\ValueObjects;

/**
 * T046: Result of querying a refund's status from the provider.
 * Used by QueryRefundStatusJob and BankRefundCallbackHandler.
 */
final readonly class RefundStatusResult
{
    /** @param 'pending'|'confirmed'|'definitive_failure' $status */
    public function __construct(
        public readonly string  $status,
        public readonly ?string $providerRef,
        public readonly string  $rawProviderCode,
    ) {
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isConfirmed(): bool
    {
        return $this->status === 'confirmed';
    }

    public function isDefinitiveFailure(): bool
    {
        return $this->status === 'definitive_failure';
    }
}
