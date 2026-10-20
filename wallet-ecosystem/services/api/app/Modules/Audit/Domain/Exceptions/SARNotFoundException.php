<?php

declare(strict_types=1);

namespace App\Modules\Audit\Domain\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

final class SARNotFoundException extends HttpException
{
    public function __construct(string $sarId)
    {
        parent::__construct(404, 'SAR not found: '.$sarId, null, [], 0);
    }
}
