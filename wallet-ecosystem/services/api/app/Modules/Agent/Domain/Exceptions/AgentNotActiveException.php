<?php

declare(strict_types=1);

namespace App\Modules\Agent\Domain\Exceptions;

final class AgentNotActiveException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Agent is not in active status.');
    }
}
