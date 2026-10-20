<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Security;

interface SecretProviderInterface
{
    public function get(string $key): string;
}
