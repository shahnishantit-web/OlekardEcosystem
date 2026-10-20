<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Database\DatabaseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Redis;

/**
 * @OA\Info(
 *     title="Wallet Ecosystem API",
 *     version="1.0.0",
 *     description="Platform Foundation API — infrastructure and cross-cutting contracts"
 * )
 * @OA\Server(url="/", description="Current environment")
 */
final class HealthController extends ApiController
{
    public function __construct(private readonly DatabaseManager $db) {}
    /**
     * @OA\Get(
     *     path="/health",
     *     summary="Liveness probe — is the process running?",
     *     tags={"Health"},
     *     @OA\Response(response=200, description="Process is alive")
     * )
     */
    public function health(): JsonResponse
    {
        return response()->json(['status' => 'ok'], 200);
    }

    /**
     * @OA\Get(
     *     path="/ready",
     *     summary="Readiness probe — can the service handle traffic?",
     *     tags={"Health"},
     *     @OA\Response(response=200, description="Service is ready"),
     *     @OA\Response(response=503, description="Service is not ready")
     * )
     */
    public function ready(): JsonResponse
    {
        $checks = [];
        $healthy = true;

        try {
            $this->db->connection('platform')->getPdo();
            $checks['database'] = 'ok';
        } catch (\Throwable) {
            $checks['database'] = 'unavailable';
            $healthy = false;
        }

        try {
            Redis::ping();
            $checks['redis'] = 'ok';
        } catch (\Throwable) {
            $checks['redis'] = 'unavailable';
            $healthy = false;
        }

        $status = $healthy ? 200 : 503;

        return response()->json([
            'status' => $healthy ? 'ready' : 'not_ready',
            'checks' => $checks,
        ], $status);
    }

    /**
     * @OA\Get(
     *     path="/startup",
     *     summary="Startup probe — has the service finished initializing?",
     *     tags={"Health"},
     *     @OA\Response(response=200, description="Startup complete")
     * )
     */
    public function startup(): JsonResponse
    {
        return response()->json(['status' => 'started'], 200);
    }
}
