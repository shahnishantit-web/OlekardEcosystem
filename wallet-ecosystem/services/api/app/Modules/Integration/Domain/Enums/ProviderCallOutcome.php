<?php

declare(strict_types=1);

namespace App\Modules\Integration\Domain\Enums;

enum ProviderCallOutcome: string
{
    case Success = 'success';
    case Failure = 'failure';
    case Timeout = 'timeout';
}
