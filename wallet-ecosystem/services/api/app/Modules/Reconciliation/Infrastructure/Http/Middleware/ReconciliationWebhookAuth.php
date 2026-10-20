<?php

declare(strict_types=1);

namespace App\Modules\Reconciliation\Infrastructure\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

final class ReconciliationWebhookAuth
{
    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        $secret = (string) config('reconciliation.webhook_secret', '');

        if ($secret === '') {
            abort(Response::HTTP_UNAUTHORIZED, 'Webhook secret not configured.');
        }

        $signature = $request->header('X-Provider-Signature', '');

        if (! is_string($signature) || $signature === '') {
            abort(Response::HTTP_UNAUTHORIZED, 'Missing X-Provider-Signature header.');
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        if (! hash_equals($expected, $signature)) {
            abort(Response::HTTP_UNAUTHORIZED, 'Invalid webhook signature.');
        }

        return $next($request);
    }
}
