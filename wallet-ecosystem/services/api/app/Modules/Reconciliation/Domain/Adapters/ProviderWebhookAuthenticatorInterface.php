<?php

declare(strict_types=1);

namespace App\Modules\Reconciliation\Domain\Adapters;

use Illuminate\Http\Request;

interface ProviderWebhookAuthenticatorInterface
{
    public function verify(Request $request): bool;
}
