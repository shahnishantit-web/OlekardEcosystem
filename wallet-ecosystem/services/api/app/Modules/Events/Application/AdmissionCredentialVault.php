<?php

declare(strict_types=1);

namespace App\Modules\Events\Application;

use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;

/**
 * Creates opaque admission secrets and their persistence representations.
 *
 * The plaintext is returned only when a credential is created. Persist the
 * hash for gate lookup and the encrypted value for authenticated ticket
 * display; never regenerate a credential when a ticket is opened.
 */
final class AdmissionCredentialVault
{
    /** @return array{plaintext: string, hash: string, encrypted: string} */
    public function create(): array
    {
        $plaintext = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return [
            'plaintext' => $plaintext,
            'hash' => hash('sha256', $plaintext),
            'encrypted' => Crypt::encryptString($plaintext),
        ];
    }

    public function matches(string $plaintext, string $storedHash): bool
    {
        if (! preg_match('/\A[A-Za-z0-9_-]{43}\z/', $plaintext)
            || ! preg_match('/\A[a-f0-9]{64}\z/', $storedHash)) {
            return false;
        }

        return hash_equals($storedHash, hash('sha256', $plaintext));
    }

    /** Recover the existing credential for authenticated display; never rotate here. */
    public function recoverForDisplay(string $encrypted): string
    {
        $plaintext = Crypt::decryptString($encrypted);
        if (! preg_match('/\A[A-Za-z0-9_-]{43}\z/', $plaintext)) {
            throw new InvalidArgumentException('Stored admission credential has an invalid format.');
        }

        return $plaintext;
    }
}
