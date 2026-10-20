<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Contracts;

interface ConfigurationServiceInterface
{
    public function get(string $key, bool $requireValue = false): mixed;

    public function getOrDefault(string $key, mixed $default): mixed;

    public function isConfigured(string $key): bool;

    public function invalidate(string $key): void;
}
