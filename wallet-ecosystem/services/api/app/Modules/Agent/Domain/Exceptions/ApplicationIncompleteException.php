<?php

declare(strict_types=1);

namespace App\Modules\Agent\Domain\Exceptions;

final class ApplicationIncompleteException extends \RuntimeException
{
    public function __construct(string $missingField = '')
    {
        $detail = $missingField !== '' ? ": {$missingField}" : '';
        parent::__construct("Agent application is incomplete{$detail}.");
    }
}
