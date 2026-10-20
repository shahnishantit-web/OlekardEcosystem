<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Exceptions;

use RuntimeException;

final class MfaRequiredException extends RuntimeException
{
    public function __construct(
        public readonly string $mfaToken,
        /** @var string[] */
        public readonly array  $methods,
        public readonly int    $expiresIn,
    ) {
        parent::__construct('MFA verification is required before a session can be issued.');
    }
}
