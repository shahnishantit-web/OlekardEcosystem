<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http\Controllers;

use App\Modules\Identity\Domain\Exceptions\ConfigurationConflictException;
use App\Modules\Identity\Domain\Services\ConfigurationAdminService;
use App\Modules\Identity\Infrastructure\Http\Requests\ConfigurationIndexRequest;
use App\Modules\Identity\Infrastructure\Http\Requests\ConfigurationUpdateRequest;
use App\Modules\Identity\Infrastructure\Security\IpHasher;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * GET  /v1/admin/platform/configuration/security
 * PUT  /v1/admin/platform/configuration/security/{key}
 *
 * Admin-only endpoints for viewing and updating all 25 E01 identity/security configuration keys.
 * Every successful PUT emits an identity.config.updated audit event (T148; FR-E01-044).
 * Cache is invalidated after successful PUT so updated values take effect immediately (T147).
 */
final class ConfigurationController extends Controller
{
    public function __construct(
        private readonly ConfigurationAdminService $adminService,
        private readonly AuditServiceInterface     $audit,
        private readonly IpHasher                  $ipHasher,
    ) {}

    /**
     * GET /v1/admin/platform/configuration/security
     * Returns all 25 E01 identity/security configuration keys with metadata.
     * Requires: identity.auth middleware + ADMIN actor_type (enforced in ConfigurationIndexRequest).
     */
    public function index(ConfigurationIndexRequest $request): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $keys          = $this->adminService->getAllKeys();

        return response()->json([
            'success' => true,
            'data'    => [
                'configuration' => $keys,
                'total'         => count($keys),
            ],
        ], 200, ['X-Correlation-ID' => $correlationId]);
    }

    /**
     * PUT /v1/admin/platform/configuration/security/{key}
     * Updates a single configuration value with typed validation and optimistic concurrency.
     * Requires: identity.auth middleware + ADMIN actor_type + Idempotency-Key header.
     */
    public function update(ConfigurationUpdateRequest $request, string $key): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $ipHash        = $this->ipHasher->hash($request->ip() ?? '0.0.0.0');
        $adminId       = (string) $request->attributes->get('identity.user_id');

        if (!$this->adminService->keyExists($key)) {
            return $this->errorResponse('IDENTITY_006', "Configuration key '{$key}' not found.", 404, $correlationId);
        }

        $newValue = (string) $request->input('value');
        $version  = (string) $request->input('version');
        $reason   = (string) $request->input('reason', '');

        try {
            $result = $this->adminService->update($key, $newValue, $version, $adminId);
        } catch (ConfigurationConflictException $e) {
            if (str_contains($e->getMessage(), 'modified by another')) {
                return $this->conflictResponse($correlationId, $key);
            }
            // Validation failure (type error, security minimum, invalid value)
            return $this->errorResponse('IDENTITY_001', $e->getMessage(), 400, $correlationId);
        }

        $meta        = $this->adminService->getMeta($key);
        $isSensitive = $meta['is_sensitive'] ?? false;

        // Audit: identity.config.updated — sensitive values masked in audit record (BR-021; T148)
        $this->audit->record(new AuditEventData(
            action:       'identity.config.updated',
            actorType:    'USER',
            resourceType: 'configuration',
            resourceId:   $key,
            correlationId: $correlationId,
            outcome:      'success',
            actorId:      $adminId,
            beforeState:  ['configuration_key' => $key, 'old_value' => $isSensitive ? '[REDACTED]' : $result['old_value']],
            afterState:   [
                'configuration_key' => $key,
                'new_value'         => $isSensitive ? '[REDACTED]' : $result['new_value'],
                'reason'            => $reason,
            ],
            ipAddress: $ipHash,
        ));

        return response()->json([
            'success' => true,
            'data'    => [
                'key'        => $result['key'],
                'old_value'  => $isSensitive ? '[REDACTED]' : $result['old_value'],
                'new_value'  => $isSensitive ? '[REDACTED]' : $result['new_value'],
                'updated_at' => $result['updated_at'],
                'updated_by' => $result['updated_by'],
            ],
        ], 200, ['X-Correlation-ID' => $correlationId]);
    }

    private function conflictResponse(string $correlationId, string $key): JsonResponse
    {
        $row = DB::connection('platform')
            ->table('platform.configuration_values')
            ->where('config_key', $key)
            ->where('status', 'active')
            ->orderByDesc('version')
            ->first(['updated_at', 'last_updated_by']);

        return response()->json([
            'success' => false,
            'error'   => [
                'code'    => 'IDENTITY_016',
                'message' => 'Configuration was updated by another administrator. Re-fetch and retry.',
                'data'    => [
                    'current_updated_at' => $row?->updated_at
                        ? Carbon::parse($row->updated_at)->toIso8601String()
                        : null,
                    'current_updated_by' => $row?->last_updated_by ?? null,
                ],
            ],
        ], 409, ['X-Correlation-ID' => $correlationId]);
    }

    private function errorResponse(string $code, string $message, int $status, string $correlationId): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error'   => ['code' => $code, 'message' => $message, 'trace_id' => $correlationId],
        ], $status, ['X-Correlation-ID' => $correlationId]);
    }
}
