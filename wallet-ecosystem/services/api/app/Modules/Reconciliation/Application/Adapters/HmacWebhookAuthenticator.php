<?php

declare(strict_types=1);

namespace App\Modules\Reconciliation\Application\Adapters;

use App\Modules\Reconciliation\Domain\Adapters\ProviderWebhookAuthenticatorInterface;
use Illuminate\Http\Request;

final class HmacWebhookAuthenticator implements ProviderWebhookAuthenticatorInterface
{
    public function verify(Request $request): bool
    {
        $secret    = (string) config('reconciliation.webhook_secret', '');
        $signature = (string) $request->header('X-Provider-Signature', '');

        if ($secret === '' || $signature === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $signature);
    }
}
