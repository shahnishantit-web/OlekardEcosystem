<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Contracts;

interface AdminPasswordResetMailContract
{
    /**
     * Queue a password-reset email for an admin account.
     *
     * @param string $email         Recipient email address
     * @param string $resetToken    Raw (plaintext) reset token — must NOT be stored
     * @param string $correlationId For log/audit correlation
     */
    public function send(string $email, string $resetToken, string $correlationId): void;
}
