<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http\Controllers;

use App\Modules\Identity\Domain\Services\JwksService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Returns the active JWK Set for ES256 signature verification.
 * Private key material MUST NOT appear in any response field.
 */
final class JwksController extends Controller
{
    public function __construct(private readonly JwksService $jwks) {}

    /**
     * GET /v1/.well-known/jwks.json
     * Public endpoint — no authentication required.
     * Cache-Control: public, max-age=300 to allow downstream caching.
     */
    public function index(Request $request): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', '');
        $keySet        = $this->jwks->getPublicKeySet();

        return response()
            ->json($keySet, 200)
            ->header('Cache-Control', 'public, max-age=300')
            ->header('X-Correlation-ID', $correlationId);
    }
}
