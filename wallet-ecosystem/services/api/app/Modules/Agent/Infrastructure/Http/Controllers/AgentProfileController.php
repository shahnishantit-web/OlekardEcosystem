<?php

declare(strict_types=1);

namespace App\Modules\Agent\Infrastructure\Http\Controllers;

use App\Modules\Agent\Application\Services\AgentProfilePayloadBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class AgentProfileController extends Controller
{
    public function __construct(private readonly AgentProfilePayloadBuilder $profiles) {}

    public function show(Request $request): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $userId = (string) $request->attributes->get('identity.user_id');
        $profile = $this->profiles->build($userId);

        if ($profile === null) {
            return response()->json([
                'success' => false,
                'data' => null,
                'error' => [
                    'code' => 'AGENT_NOT_FOUND',
                    'message' => 'Agent profile not found.',
                ],
                'correlation_id' => $correlationId,
                'meta' => null,
            ], 404, ['X-Correlation-ID' => $correlationId]);
        }

        if ($profile['status'] !== 'active') {
            return response()->json([
                'success' => false,
                'data' => null,
                'error' => [
                    'code' => 'AGENT_NOT_ACTIVE',
                    'message' => 'Agent account is not active.',
                ],
                'correlation_id' => $correlationId,
                'meta' => null,
            ], 403, ['X-Correlation-ID' => $correlationId]);
        }

        return response()->json([
            'success' => true,
            'data' => $profile,
            'error' => null,
            'correlation_id' => $correlationId,
            'meta' => null,
        ], 200, ['X-Correlation-ID' => $correlationId]);
    }
}
