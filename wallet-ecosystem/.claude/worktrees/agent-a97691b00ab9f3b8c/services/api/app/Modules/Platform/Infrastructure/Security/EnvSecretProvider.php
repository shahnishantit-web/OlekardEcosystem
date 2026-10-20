<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Security;

final class EnvSecretProvider implements SecretProviderInterface
{
    public function get(string $key): string
    {
        $value = $_ENV[$key] ?? null;

        if ($value === null || $value === '') {
            throw new \RuntimeException("Required secret '{$key}' is not set in the environment.");
        }

        return (string) $value;
    }
}
