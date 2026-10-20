<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http\Controllers;

use App\Modules\Identity\Application\Commands\IssueServiceTokenCommand;
use App\Modules\Identity\Domain\Exceptions\InvalidCredentialsException;
use App\Modules\Identity\Domain\Services\ServiceIdentityService;
use App\Modules\Identity\Infrastructure\Security\IpHasher;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

/**
 * POST /v1/auth/service/token
 *
 * Issues an ES256 service-identity JWT for authenticated internal services.
 * Idempotency enforced via Idempotency-Key header (IdempotencyMiddleware).
 * Emits identity.service.token-issued audit event on success (spec Section 16; T139).
 *
 * Service credential: TBD mechanism — current stub uses X-Service-Secret header
 * matched against IDENTITY_SERVICE_SECRET env (platform secret manager proxy).
 */
final class ServiceTokenController extends Controller
{
    public function __construct(
        private readonly ServiceIdentityService $serviceIdentity,
        private readonly AuditServiceInterface  $audit,
        private readonly IpHasher               $ipHasher,
    ) {}

    public function issue(Request $request): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $ipHash        = $this->ipHasher->hash($request->ip() ?? '0.0.0.0');

        $serviceId  = (string) $request->input('service_id', '');
        $scopes     = (array)  $request->input('scopes', []);
        $audience   = (string) $request->input('audience', '');
        $onBehalfOf = $request->input('on_behalf_of');
        $credential = (string) $request->header('X-Service-Secret', '');

        if ($serviceId === '' || $audience === '' || empty($scopes)) {
            return $this->errorResponse('IDENTITY_001', 'service_id, scopes, and audience are required.', 400, $correlationId);
        }

        try {
            $tokenDto = $this->serviceIdentity->issue(
                new IssueServiceTokenCommand(
                    serviceId:     $serviceId,
                    scopes:        $scopes,
                    audience:      $audience,
                    onBehalfOf:    $onBehalfOf !== null ? (string) $onBehalfOf : null,
                    correlationId: $correlationId,
                ),
                $credential,
            );
        } catch (InvalidCredentialsException) {
            return $this->errorResponse('IDENTITY_002', 'Invalid service credentials.', 401, $correlationId);
        }

        // Parse jti and exp from the issued JWT for audit (no raw secret is logged)
        $parts  = explode('.', $tokenDto->accessToken);
        $claims = count($parts) === 3
            ? (json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true) ?? [])
            : [];

        $this->audit->record(new AuditEventData(
            action:        'identity.service.token-issued',
            actorType:     'SERVICE',
            resourceType:  'identity.service_token',
            correlationId: $correlationId,
            outcome:       'success',
            actorId:       null,  // service_id is not a UUID; captured in afterState
            afterState:    array_filter([
                'service_id'   => $serviceId,
                'jti'          => $claims['jti'] ?? '',
                'aud'          => $audience,
                'iat'          => $claims['iat'] ?? 0,
                'exp'          => $claims['exp'] ?? 0,
                'scopes'       => $scopes,
                'on_behalf_of' => $onBehalfOf,  // spec 10.5: both identities MUST be auditable
            ], static fn ($v) => $v !== null),
            ipAddress: $ipHash,
        ));

        return response()->json([
            'success' => true,
            'data'    => [
                'access_token' => $tokenDto->accessToken,
                'token_type'   => $tokenDto->tokenType,
                'expires_in'   => $tokenDto->expiresIn,
                'scopes'       => $tokenDto->scopes,
            ],
        ], 200, ['X-Correlation-ID' => $correlationId]);
    }

    private function errorResponse(string $code, string $message, int $status, string $correlationId): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error'   => ['code' => $code, 'message' => $message, 'trace_id' => $correlationId],
        ], $status, ['X-Correlation-ID' => $correlationId]);
    }
}
