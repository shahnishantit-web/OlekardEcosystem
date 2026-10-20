<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\Exceptions;

use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

final class ConcurrentJobLimitExceededException extends TooManyRequestsHttpException
{
    public function __construct(
        public readonly int $activeCount,
        public readonly int $limit,
    ) {
        parent::__construct(
            message: "Concurrent job limit of {$limit} reached (active: {$activeCount}).",
            code: 0,
        );
    }

    public function getErrorCode(): string
    {
        return 'CONCURRENT_JOB_LIMIT_EXCEEDED';
    }
}
