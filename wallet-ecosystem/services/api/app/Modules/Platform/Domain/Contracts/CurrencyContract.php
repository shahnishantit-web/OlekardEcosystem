<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Contracts;

interface CurrencyContract
{
    public function code(): string;

    public function precision(): int;

    public function name(): string;

    public function isSupported(): bool;
}
