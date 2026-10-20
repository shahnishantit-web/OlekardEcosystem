<?php

declare(strict_types=1);

namespace App\Modules\Agent\Domain\Exceptions;

final class SearchQueryTooShortException extends \RuntimeException
{
    public function __construct(int $minLength)
    {
        parent::__construct("Search query must be at least {$minLength} characters.");
    }
}
