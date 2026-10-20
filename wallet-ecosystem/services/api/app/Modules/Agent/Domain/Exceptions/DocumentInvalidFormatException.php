<?php

declare(strict_types=1);

namespace App\Modules\Agent\Domain\Exceptions;

final class DocumentInvalidFormatException extends \RuntimeException
{
    public function __construct(string $mimeType = '')
    {
        $detail = $mimeType !== '' ? " (received: {$mimeType})" : '';
        parent::__construct("Document format is not allowed{$detail}.");
    }
}
