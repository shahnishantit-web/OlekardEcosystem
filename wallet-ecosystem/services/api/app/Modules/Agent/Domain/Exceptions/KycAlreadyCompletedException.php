<?php

declare(strict_types=1);

namespace App\Modules\Agent\Domain\Exceptions;

final class KycAlreadyCompletedException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Customer KYC has already been completed.');
    }
}
