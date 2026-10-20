<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Rules;

use App\Modules\Identity\Domain\Services\RuntimeSecurityPolicy;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class ConfiguredPassword implements ValidationRule
{
    public function __construct(private readonly bool $creation = false) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        foreach (app(RuntimeSecurityPolicy::class)->passwordErrors((string) $value, $this->creation) as $error) {
            $fail($error);
        }
    }
}
