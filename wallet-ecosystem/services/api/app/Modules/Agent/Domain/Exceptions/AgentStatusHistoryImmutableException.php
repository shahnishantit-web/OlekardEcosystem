<?php

declare(strict_types=1);

namespace App\Modules\Agent\Domain\Exceptions;

final class AgentStatusHistoryImmutableException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Agent status history records are immutable and cannot be modified or deleted.');
    }
}
