<?php

declare(strict_types=1);

namespace App\Modules\Transfer\Domain\Exceptions;

/**
 * Transfer domain wrapper for PIN verification failure.
 * Thrown by TransferConfirmationService when IdentityModuleContract returns a PIN failure.
 * Allows TransferController to map to 422 INVALID_PIN without importing E05's exception class.
 */
final class InvalidPinException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Transaction PIN verification failed.');
    }
}
