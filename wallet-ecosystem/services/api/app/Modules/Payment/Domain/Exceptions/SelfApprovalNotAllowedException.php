<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Exceptions;

final class SelfApprovalNotAllowedException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('The requester of a change cannot also approve it.');
    }
}
