<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Contracts;

use App\Modules\Platform\Domain\Enums\ConfigurationDomain;
use Illuminate\Validation\ValidationException;

interface ConfigurationDomainValidatorInterface
{
    /**
     * Validate a proposed configuration value for the given key.
     *
     * @throws ValidationException if the value fails domain-specific schema rules
     */
    public function validate(string $key, mixed $value): void;

    public function supportsDomain(ConfigurationDomain $domain): bool;
}
