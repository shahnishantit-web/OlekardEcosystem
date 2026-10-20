<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Contracts;

interface JwksProviderContract
{
    public function getSigningKey(): JwkSigningKey;

    /** @return JwkPublicKey[] */
    public function getVerificationKeys(): array;

    public function getCurrentKid(): string;
}
