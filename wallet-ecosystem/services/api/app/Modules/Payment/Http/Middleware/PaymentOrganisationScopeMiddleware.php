<?php

declare(strict_types=1);

namespace App\Modules\Payment\Http\Middleware;

use App\Modules\Payment\Domain\Contracts\PaymentIntentRepositoryInterface;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * T150: Validates that the resolved payment intent belongs to the authenticated
 * actor's organisation. Returns 403 on mismatch to prevent cross-org data leakage.
 *
 * Applied to routes that include {intentId} and require tenant isolation.
 */
final class PaymentOrganisationScopeMiddleware
{
    public function __construct(
        private readonly PaymentIntentRepositoryInterface $intentRepository,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $intentId = $request->route('intentId');

        if ($intentId === null) {
            return $next($request);
        }

        /** @var \App\Modules\Platform\Domain\ValueObjects\Actor|null $actor */
        $actor = $request->attributes->get('actor');

        if ($actor === null) {
            return response()->json([
                'error' => 'UNAUTHENTICATED',
                'message' => 'Authentication required.',
            ], 401);
        }

        $intent = $this->intentRepository->findById((string) $intentId);

        if ($intent === null) {
            // Let the controller handle not-found — scope middleware should not reveal existence
            return $next($request);
        }

        // Server-side org scope check — never trust caller-supplied org claims
        if ($actor->organisationId !== null && $intent->organisation_id !== $actor->organisationId) {
            return response()->json([
                'error'   => 'FORBIDDEN',
                'message' => 'Access denied: intent does not belong to your organisation.',
            ], 403);
        }

        return $next($request);
    }
}
