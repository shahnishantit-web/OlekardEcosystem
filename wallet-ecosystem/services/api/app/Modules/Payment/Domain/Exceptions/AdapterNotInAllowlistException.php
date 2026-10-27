<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Exceptions;

final class AdapterNotInAllowlistException extends \RuntimeException
{
    public function __construct(public readonly string $adapterIdentifier)
    {
        parent::__construct("Adapter '{$adapterIdentifier}' is not in the installed adapter allowlist.");
    }
}
