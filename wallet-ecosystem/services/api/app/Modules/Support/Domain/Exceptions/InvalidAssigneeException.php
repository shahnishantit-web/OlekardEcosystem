<?php

declare(strict_types=1);

namespace App\Modules\Support\Domain\Exceptions;

final class InvalidAssigneeException extends \DomainException
{
    public readonly string $errorCode;
    public readonly int    $httpStatus;

    public function __construct(string $message = 'The specified assignee is not a valid support agent.')
    {
        parent::__construct($message);
        $this->errorCode  = 'INVALID_ASSIGNEE';
        $this->httpStatus = 422;
    }
}
