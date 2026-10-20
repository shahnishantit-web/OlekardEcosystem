<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Modules\Platform\Domain\ValueObjects\CorrelationId;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

final class Handler
{
    public static function register(Exceptions $exceptions): void
    {
        // Suppress auto-reporting for exceptions that our renderer already logs explicitly.
        $exceptions->dontReport([
            ModuleConnectionUnavailableException::class,
            IdempotencyKeyMismatchException::class,
            IdempotencyInProgressException::class,
            TbdConfigurationException::class,
        ]);

        $exceptions->render(function (\Throwable $e, Request $request): ?JsonResponse {
            if (! $request->expectsJson() && ! $request->is('v1/*') && ! $request->is('api/v1/*') && ! $request->is('health') && ! $request->is('ready') && ! $request->is('startup')) {
                return null;
            }

            $correlationId = $request->header('X-Correlation-ID', '');

            // QueryException wrapping a PDO connection failure must be detected before the
            // generic default branch, so module connection errors map to 503 SERVICE_UNAVAILABLE.
            if ($e instanceof QueryException && self::isConnectionFailure($e)) {
                $connectionUnavailable = new ModuleConnectionUnavailableException(
                    moduleName:     'unknown',
                    connectionName: $e->getConnectionName(),
                    correlationId:  $correlationId,
                    previous:       $e,
                );
                Log::error('Module database connection unavailable', [
                    'module_name'     => 'unknown',
                    'connection_name' => $e->getConnectionName(),
                    'correlation_id'  => $correlationId,
                    'exception'       => $e->getMessage(),
                ]);

                return self::serviceUnavailableError($connectionUnavailable, $correlationId);
            }

            return match (true) {
                $e instanceof ValidationException              => self::validationError($e, $correlationId),
                $e instanceof AuthenticationException          => self::authError($e, $correlationId),
                $e instanceof AuthorizationException           => self::forbiddenError($e, $correlationId),
                $e instanceof NotFoundHttpException            => self::notFoundError($correlationId),
                $e instanceof MethodNotAllowedHttpException    => self::errorEnvelope(405, 'METHOD_NOT_ALLOWED', 'HTTP method not allowed.', [], $correlationId),
                $e instanceof ResourceNotFoundException         => self::notFoundError($correlationId),
                $e instanceof IdempotencyKeyMismatchException  => self::conflict('IDEMPOTENCY_KEY_MISMATCH', $e->getMessage(), $correlationId),
                $e instanceof IdempotencyInProgressException   => self::conflict('IDEMPOTENCY_IN_PROGRESS', $e->getMessage(), $correlationId),
                $e instanceof DuplicateResourceException       => self::conflict('RESOURCE_CONFLICT', $e->getMessage(), $correlationId),
                $e instanceof BusinessRuleViolationException   => self::businessRuleError($e, $correlationId),
                $e instanceof TbdConfigurationException        => self::tbdConfigError($e, $correlationId),
                $e instanceof ThrottleRequestsException            => self::rateLimitError($e, $correlationId),
                $e instanceof ModuleConnectionUnavailableException => self::serviceUnavailableError($e, $correlationId),
                $e instanceof ProviderException                => self::providerError($e, $correlationId),
                default                                       => self::internalError($e, $correlationId),
            };
        });
    }

    private static function validationError(ValidationException $e, string $correlationId): JsonResponse
    {
        $details = [];
        foreach ($e->errors() as $field => $messages) {
            foreach ($messages as $message) {
                $details[] = [
                    'field'   => $field,
                    'code'    => 'VALIDATION_ERROR',
                    'message' => $message,
                ];
            }
        }

        return self::errorEnvelope(422, 'VALIDATION_ERROR', 'The given data was invalid.', $details, $correlationId);
    }

    private static function authError(AuthenticationException $e, string $correlationId): JsonResponse
    {
        return self::errorEnvelope(401, 'AUTHENTICATION_REQUIRED', 'Authentication required.', [], $correlationId);
    }

    private static function forbiddenError(AuthorizationException $e, string $correlationId): JsonResponse
    {
        return self::errorEnvelope(403, 'PERMISSION_DENIED', 'You do not have permission to perform this action.', [], $correlationId);
    }

    private static function notFoundError(string $correlationId): JsonResponse
    {
        return self::errorEnvelope(404, 'RESOURCE_NOT_FOUND', 'The requested resource does not exist.', [], $correlationId);
    }

    private static function conflict(string $code, string $message, string $correlationId): JsonResponse
    {
        return self::errorEnvelope(409, $code, $message, [], $correlationId);
    }

    private static function businessRuleError(BusinessRuleViolationException $e, string $correlationId): JsonResponse
    {
        return self::errorEnvelope(400, 'BUSINESS_RULE_VIOLATION', $e->getMessage(), [], $correlationId);
    }

    private static function tbdConfigError(TbdConfigurationException $e, string $correlationId): JsonResponse
    {
        return self::errorEnvelope(503, 'TBD_CONFIGURATION', $e->getMessage(), [], $correlationId, retryAfter: $e->getRetryAfter());
    }

    private static function serviceUnavailableError(ModuleConnectionUnavailableException $e, string $correlationId): JsonResponse
    {
        Log::error('Module connection unavailable', [
            'module_name'     => $e->getModuleName(),
            'connection_name' => $e->getConnectionName(),
            'correlation_id'  => $correlationId,
            'exception'       => $e->getMessage(),
        ]);

        $response = self::errorEnvelope(503, 'SERVICE_UNAVAILABLE', 'A required module dependency is currently unavailable.', [], $correlationId);
        $response->headers->set('Retry-After', '60');

        return $response;
    }

    /**
     * Returns true only for genuine named-connection availability failures.
     * Arbitrary SQL/query errors (syntax, constraint violations) return false.
     */
    private static function isConnectionFailure(QueryException $e): bool
    {
        $pdoMessage = $e->getPrevious()?->getMessage() ?? '';
        $patterns   = [
            'Connection refused',
            'could not connect to server',
            'could not connect',
            'No such file or directory',  // Unix socket not found
            'SQLSTATE[HY000] [2002]',     // PHP PDO connection timeout
            'SQLSTATE[08006]',            // PostgreSQL connection failure
            'host unreachable',
            'Network is unreachable',
        ];

        foreach ($patterns as $pattern) {
            if (str_contains($pdoMessage, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private static function rateLimitError(ThrottleRequestsException $e, string $correlationId): JsonResponse
    {
        $headers      = $e->getHeaders();
        $retryAfter   = isset($headers['Retry-After']) ? (int) $headers['Retry-After'] : null;

        return self::errorEnvelope(429, 'RATE_LIMIT_EXCEEDED', 'Too many requests — please slow down.', [], $correlationId, $retryAfter);
    }

    private static function providerError(ProviderException $e, string $correlationId): JsonResponse
    {
        $code = $e->isTimeout() ? 'PROVIDER_TIMEOUT' : 'PROVIDER_ERROR';
        $status = $e->isTimeout() ? 504 : 502;

        return self::errorEnvelope($status, $code, 'An external provider returned an error.', [], $correlationId);
    }

    private static function internalError(\Throwable $e, string $correlationId): JsonResponse
    {
        Log::error($e->getMessage(), ['exception' => $e, 'correlation_id' => $correlationId]);

        return self::errorEnvelope(500, 'INTERNAL_ERROR', 'An unexpected error occurred. Please retry or contact support.', [], $correlationId);
    }

    private static function errorEnvelope(
        int $status,
        string $code,
        string $message,
        array $details,
        string $correlationId,
        ?int $retryAfter = null,
    ): JsonResponse {
        $response = new JsonResponse([
            'success'        => false,
            'data'           => null,
            'error'          => [
                'code'    => $code,
                'message' => $message,
                'details' => $details,
            ],
            'correlation_id' => $correlationId,
            'meta'           => null,
        ], $status);

        if ($retryAfter !== null) {
            $response->headers->set('Retry-After', (string) $retryAfter);
        }

        return $response;
    }
}
