<?php

declare(strict_types=1);

namespace App\Modules\Audit\Domain\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

final class ComplianceStatusNotFoundException extends HttpException
{
    public function __construct(string $userId)
    {
        parent::__construct(404, 'Compliance status not found for user: '.$userId, null, [], 0);
    }
}
