<?php

declare(strict_types=1);

namespace App\Modules\Transfer\Domain\Exceptions;

final class TransferReceiptImmutableException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('TransferReceipt is immutable after initial insert.');
    }
}
