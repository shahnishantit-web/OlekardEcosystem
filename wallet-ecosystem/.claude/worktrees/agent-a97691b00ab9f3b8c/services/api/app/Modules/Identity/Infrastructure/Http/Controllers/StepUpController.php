<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http\Controllers;

use App\Modules\Identity\Application\Commands\CompleteStepUpCommand;
use App\Modules\Identity\Domain\Events\StepUpCompletedEvent;
use App\Modules\Identity\Domain\Events\StepUpRequiredEvent;
use App\Modules\Identity\Domain\Exceptions\InvalidCredentialsException;
use App\Modules\Identity\Domain\Services\IdentityMetrics;
use App\Modules\Identity\Domain\Services\StepUpService;
use App\Modules\Identity\Infrastructure\Security\IpHasher;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

/**
 * Step-Up Authentication endpoints.
 *
 * POST /v1/auth/step-up/initiate — evaluate threshold, issue challenge token (FR-E01-037)
 * POST /v1/auth/step-up/verify  — verify credential, issue scoped step-up JWT (FR-E01-037; FR-E01-038)
 *
 * Both endpoints require a valid Bearer JWT (identity.auth middleware).
 * Step-up tokens are purpose-scoped (aud = "step-up:{scope}") and MUST NOT
 * be accepted by general-access endpoints (AC-013; T132).
 */
final class StepUpController extends Controller
{
    public function __construct(
        private readonly StepUpService          $stepUp,
        private readonly IdentityMetrics        $metrics,
        private readonly AuditServiceInterface  $audit,
        private readonly EventPublisherInterface $events,
        private readonly IpHasher               $ipHasher,
    ) {}

    /**
     * POST /v1/auth/step-up/initiate
     *
     * Evaluate whether step-up is required for the given operation context.
     * If required: issue challenge token + return available methods.
     * If not required: return step_up_required=false (no token issued).
     */
    public function initiate(Request $request): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $userId        = (string) $request->attributes->get('identity.user_id');
        $sessionId     = (string) $request->attributes->get('identity.session_id');
        $actorType     = (string) $request->attributes->get('identity.actor_type', 'CUSTOMER');

        $scope       = (string) $request->input('scope', '');
        $amount      = (float)  $request->input('operation_context.amount', 0.0);
        $currency    = (string) $request->input('operation_context.currency', '');
        $riskSignal  = (bool)   $request->input('operation_context.risk_required', false);

        $amountRequired = $this->stepUp->evaluateAmount($amount, $currency);
        $riskRequired   = $this->stepUp->evaluateRiskSignal($riskSignal);
        $stepUpRequired = $amountRequired || $riskRequired;

        if (!$stepUpRequired) {
            return response()->json([
                'success' => true,
                'data'    => ['step_up_required' => false],
            ], 200, ['X-Correlation-ID' => $correlationId]);
        }

        $this->metrics->stepUpTriggered($amountRequired ? 'amount_threshold' : 'risk_signal');

        $challenge = $this->stepUp->initiateChallenge(
            userId:        $userId,
            sessionId:     $sessionId,
            actorType:     $actorType,
            scope:         $scope,
            correlationId: $correlationId,
        );

        $this->events->publish(new StepUpRequiredEvent(
            sessionId:     $sessionId,
            actorId:       $userId,
            correlationId: $correlationId,
            userId:        $userId,
            actorType:     $actorType,
            scope:         $scope,
        ));

        return response()->json([
            'success' => true,
            'data'    => [
                'step_up_required' => true,
                'step_up_token'    => $challenge['step_up_token'],
                'methods'          => $challenge['methods'],
                'expires_at'       => $challenge['expires_at'],
            ],
        ], 200, ['X-Correlation-ID' => $correlationId]);
    }

    /**
     * POST /v1/auth/step-up/verify
     *
     * Verify the step-up challenge credential and issue a purpose-scoped step-up JWT.
     * Emits identity.mfa.step-up-completed or identity.mfa.step-up-failed (T129; AC-015).
     */
    public function verify(Request $request): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $userId        = (string) $request->attributes->get('identity.user_id');
        $sessionId     = (string) $request->attributes->get('identity.session_id');
        $actorType     = (string) $request->attributes->get('identity.actor_type', 'CUSTOMER');
        $ipHash        = $this->ipHasher->hash($request->ip() ?? '0.0.0.0');

        $stepUpToken = (string) $request->input('step_up_token', '');
        $method      = (string) $request->input('method', '');
        $credential  = (string) $request->input('credential', '');

        if ($stepUpToken === '' || $method === '' || $credential === '') {
            return $this->errorResponse('IDENTITY_019', 'step_up_token, method, and credential are required.', 422, $correlationId);
        }

        try {
            $tokenDto = $this->stepUp->verifyChallenge(new CompleteStepUpCommand(
                stepUpToken:   $stepUpToken,
                method:        $method,
                credential:    $credential,
                userId:        $userId,
                sessionId:     $sessionId,
                actorType:     $actorType,
                correlationId: $correlationId,
                ipHash:        $ipHash,
            ));
        } catch (InvalidCredentialsException) {
            $this->audit->record(new AuditEventData(
                action:        'identity.mfa.step-up-failed',
                actorType:     $actorType,
                resourceType:  'identity.session',
                correlationId: $correlationId,
                outcome:       'failure',
                actorId:       $userId,
                resourceId:    $sessionId,
                ipAddress:     $ipHash,
            ));

            return $this->errorResponse('IDENTITY_014', 'Invalid or expired step-up token or credential.', 401, $correlationId);
        }

        $this->audit->record(new AuditEventData(
            action:        'identity.mfa.step-up-completed',
            actorType:     $actorType,
            resourceType:  'identity.session',
            correlationId: $correlationId,
            outcome:       'success',
            actorId:       $userId,
            resourceId:    $sessionId,
            afterState:    ['scope' => $tokenDto->scope],
            ipAddress:     $ipHash,
        ));

        $this->events->publish(new StepUpCompletedEvent(
            sessionId:     $sessionId,
            actorId:       $userId,
            correlationId: $correlationId,
            userId:        $userId,
            actorType:     $actorType,
            scope:         $tokenDto->scope,
        ));

        return response()->json([
            'success' => true,
            'data'    => [
                'step_up_access_token' => $tokenDto->stepUpAccessToken,
                'scope'                => $tokenDto->scope,
                'expires_in'           => $tokenDto->expiresIn,
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
