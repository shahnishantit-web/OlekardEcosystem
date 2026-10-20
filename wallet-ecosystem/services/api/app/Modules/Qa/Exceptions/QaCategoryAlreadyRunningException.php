<?php

declare(strict_types=1);

namespace App\Modules\Qa\Exceptions;

use App\Modules\Qa\Domain\Enums\QaCategory;

final class QaCategoryAlreadyRunningException extends \RuntimeException
{
    public function __construct(public readonly QaCategory $category)
    {
        parent::__construct(
            "A QA run for category '{$category->value}' is already in progress."
        );
    }
}
