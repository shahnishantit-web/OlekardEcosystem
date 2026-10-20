<?php

declare(strict_types=1);

namespace App\Modules\Agent\Domain\Exceptions;

final class DocumentVirusDetectedException extends \RuntimeException
{
    public function __construct(string $threatName = '')
    {
        $detail = $threatName !== '' ? " ({$threatName})" : '';
        parent::__construct("Virus detected in uploaded document{$detail}.");
    }
}
