<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Exceptions;

final class PurposeNotPayableException extends \RuntimeException
{
    public function __construct(string $purposeType, string $purposeReference, string $reason = '')
    {
        $msg = "Purpose [{$purposeType}:{$purposeReference}] is not payable.";
        if ($reason !== '') {
            $msg .= " Reason: {$reason}";
        }
        parent::__construct($msg);
    }
}
