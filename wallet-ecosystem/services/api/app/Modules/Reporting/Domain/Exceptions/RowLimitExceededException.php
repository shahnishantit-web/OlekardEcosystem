<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\Exceptions;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class RowLimitExceededException extends UnprocessableEntityHttpException
{
    public function __construct(
        public readonly int $rowCount,
        public readonly int $limit,
    ) {
        parent::__construct(
            message: "Estimated row count {$rowCount} exceeds the maximum limit of {$limit}.",
            code: 0,
        );
    }

    public function getErrorCode(): string
    {
        return 'ROW_LIMIT_EXCEEDED';
    }
}
