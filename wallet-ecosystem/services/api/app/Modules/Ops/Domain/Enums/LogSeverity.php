<?php

declare(strict_types=1);

namespace App\Modules\Ops\Domain\Enums;

enum LogSeverity: string
{
    case Debug    = 'debug';
    case Info     = 'info';
    case Warning  = 'warning';
    case Error    = 'error';
    case Critical = 'critical';
}
