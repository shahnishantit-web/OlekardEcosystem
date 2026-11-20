<?php

declare(strict_types=1);

namespace App\Modules\CommunityFuneral\Http\Middleware;

use App\Modules\CommunityFuneral\Application\FuneralFeatureReadiness;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Keep P24 HTTP entry points closed until tenant and payout prerequisites are proven. */
final class FuneralReadinessGate
{
    public function __construct(private readonly FuneralFeatureReadiness $readiness) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->readiness->isEnabled()) {
            return response()->json(['success' => false, 'error' => [
                'code' => 'FUNERAL_ASSISTANCE_PREREQUISITES_UNRESOLVED',
                'message' => 'Funeral Assistance is unavailable pending tenant scope and shared payout readiness.',
            ]], 503);
        }

        return $next($request);
    }
}
