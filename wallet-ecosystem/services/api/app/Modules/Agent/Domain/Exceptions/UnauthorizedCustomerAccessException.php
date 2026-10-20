<?php

declare(strict_types=1);

namespace App\Modules\Agent\Domain\Exceptions;

final class UnauthorizedCustomerAccessException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Agent is not authorized to access this customer.');
    }
}
