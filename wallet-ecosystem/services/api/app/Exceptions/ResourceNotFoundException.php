<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class ResourceNotFoundException extends RuntimeException
{
    public function __construct(string $resourceType, string $id)
    {
        parent::__construct("{$resourceType} {$id} not found.");
    }
}
