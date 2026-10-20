<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Contracts;

interface AdminEmailVerificationMailContract
{
    /**
     * Queue an email-verification link for a newly-provisioned admin account.
     *
     * @param string $email         Recipient email address
     * @param string $verifyToken   Raw (plaintext) verification token — must NOT be stored
     * @param string $correlationId For log/audit correlation
     */
    public function send(string $email, string $verifyToken, string $correlationId): void;
}
