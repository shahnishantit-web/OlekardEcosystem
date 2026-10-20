<?php

declare(strict_types=1);

namespace App\Modules\Agent\Domain\Exceptions;

final class DocumentTooLargeException extends \RuntimeException
{
    public function __construct(int $maxBytes)
    {
        parent::__construct(sprintf('Document exceeds maximum allowed size of %d bytes.', $maxBytes));
    }
}
