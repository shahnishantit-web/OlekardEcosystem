<?php

declare(strict_types=1);

use App\Modules\Events\Application\EventAdmissionService;
use App\Modules\Events\Application\EventCancellationService;
use App\Modules\Events\Application\FreeEventRegistrationService;
use App\Modules\Events\Domain\Contracts\ComplimentaryAllocationApprovalContract;
use App\Modules\Events\Domain\Contracts\InvitationRecipientVerifier;
use App\Modules\Events\Domain\ValueObjects\ComplimentaryAllocationApprovalEvidence;
use App\Modules\Identity\Domain\Contracts\ActorStepUpVerifierContract;
use App\Modules\Identity\Domain\Contracts\IdentityModuleContract;
use App\Modules\Payment\Domain\Contracts\PaymentRefundAdmissionEligibilityContract;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$app->instance(AuditServiceInterface::class, new class implements AuditServiceInterface
{
    public function record(AuditEventData $event): void {}
});
$app->instance(ComplimentaryAllocationApprovalContract::class, new class implements ComplimentaryAllocationApprovalContract
{
    public function approvalEvidence(string $organisationId, string $eventId, string $categoryId): ComplimentaryAllocationApprovalEvidence
    {
        throw new LogicException('Free-registration concurrency fixtures must not request complimentary approval.');
    }
});
$identity = Mockery::mock(IdentityModuleContract::class);
$identity->shouldReceive('hasPermission')->andReturn(true);
$app->instance(IdentityModuleContract::class, $identity);
$app->instance(InvitationRecipientVerifier::class, new class implements InvitationRecipientVerifier
{
    public function assertActiveCustomerActor(string $actorId): void {}
});
$stepUp = Mockery::mock(ActorStepUpVerifierContract::class);
$stepUp->shouldReceive('verifyStepUpForActor')->andReturn(true);
$app->instance(ActorStepUpVerifierContract::class, $stepUp);
$refunds = Mockery::mock(PaymentRefundAdmissionEligibilityContract::class);
$refunds->shouldReceive('hasAdmissionBlockingRefund')->andReturn(false);
$app->instance(PaymentRefundAdmissionEligibilityContract::class, $refunds);

[$script, $eventId, $categoryId, $actorId, $key, $token, $barrier, $workerId, $operation, $invitationId,
    $cancellationId, $gateId, $credential] = array_pad($argv, 13, '-');
$db = DB::connection('community_services');
if ($db->getDriverName() !== 'pgsql') {
    fwrite(STDERR, 'P26 concurrency verification requires PostgreSQL.');
    exit(2);
}
$pid = (int) $db->selectOne('select pg_backend_pid() as pid')->pid;
file_put_contents($barrier.'.'.$workerId.'.ready', (string) $pid, LOCK_EX);
$deadline = microtime(true) + 30;
while (! is_file($barrier.'.go')) {
    if (microtime(true) >= $deadline) {
        fwrite(STDERR, 'Timed out waiting for race barrier.');
        exit(2);
    }
    usleep(10000);
}

try {
    if ($operation === 'revoke') {
        $result = app(FreeEventRegistrationService::class)->revokeInvitation($invitationId, $actorId, $workerId);
        echo json_encode(['outcome' => 'revoked', 'invitation_id' => $result['invitation_id'], 'pid' => $pid], JSON_THROW_ON_ERROR);
    } elseif ($operation === 'register') {
        $result = app(FreeEventRegistrationService::class)->register(
            $eventId, $categoryId, $actorId, $token === '-' ? null : $token, $key, $workerId,
        );
        echo json_encode(['outcome' => 'registered', 'registration_id' => $result['registration_id'], 'ticket_id' => $result['ticket_id'], 'pid' => $pid], JSON_THROW_ON_ERROR);
    } elseif ($operation === 'cancel') {
        $result = app(EventCancellationService::class)->review(
            $eventId, $cancellationId, $actorId, 1, true, null, $key, $workerId,
        );
        echo json_encode(['outcome' => 'cancelled', 'status' => $result['status'], 'pid' => $pid], JSON_THROW_ON_ERROR);
    } elseif ($operation === 'admit') {
        $result = app(EventAdmissionService::class)->validateOnline($eventId, $gateId, $actorId, $credential, $workerId);
        echo json_encode(['outcome' => $result['status'], 'pid' => $pid], JSON_THROW_ON_ERROR);
    } else {
        throw new InvalidArgumentException('Unknown P26 worker operation.');
    }
} catch (Throwable $exception) {
    $status = method_exists($exception, 'getStatusCode') ? $exception->getStatusCode() : null;
    echo json_encode(['outcome' => 'denied', 'status' => $status, 'error' => $exception::class, 'pid' => $pid], JSON_THROW_ON_ERROR);
}
