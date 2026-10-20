<?php

declare(strict_types=1);

namespace App\Modules\AgentFloat\Domain\Exceptions;

final class InsufficientCustomerBalanceException extends \DomainException
{
    public function __construct(
        public readonly string $customerId,
        public readonly string $errorCode = 'INSUFFICIENT_CUSTOMER_BALANCE',
    ) {
        parent::__construct("Customer [{$customerId}] has insufficient balance for cash-out.");
    }

    public static function forCustomer(string $customerId): self
    {
        return new self($customerId);
    }
}
