<?php

declare(strict_types=1);

namespace App\Modules\Support\Infrastructure\Http\Middleware;

use App\Modules\Identity\Domain\Contracts\ActorStepUpVerifierContract;
use Closure;
use Illuminate\Http\Request;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Response;

final class RefundStepUpMiddleware
{
    public function __construct(private readonly ActorStepUpVerifierContract $identity) {}

    public function handle(Request $r, Closure $next): Response
    {
        abort_unless(Uuid::isValid((string) $r->header('Idempotency-Key')), 422, 'Valid Idempotency-Key required');
        abort_unless($this->identity->verifyStepUpForActor((string) $r->input('step_up_token', ''), 'support.refund:'.$r->route('id'), (string) $r->attributes->get('identity.user_id'), (string) $r->attributes->get('identity.session_id')), 403, 'Step-up verification required');

        return $next($r);
    }
}
