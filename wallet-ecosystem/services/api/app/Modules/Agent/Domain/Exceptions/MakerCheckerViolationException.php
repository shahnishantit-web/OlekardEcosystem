<?php

declare(strict_types=1);

namespace App\Modules\Agent\Domain\Exceptions;

final class MakerCheckerViolationException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Maker-checker violation: the approver cannot be the same actor who last updated the application.');
    }
}
