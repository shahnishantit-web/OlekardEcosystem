<?php

declare(strict_types=1);

use App\Modules\Events\Application\EventAdmissionService;
use App\Modules\Events\Application\EventAssignmentManagementService;
use App\Modules\Identity\Domain\Contracts\ActorStepUpVerifierContract;
use App\Modules\Identity\Domain\Contracts\IdentityModuleContract;
use App\Modules\Identity\Domain\Contracts\OrganisationMembershipAuthority;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use Illuminate\Contracts\Console\Kernel;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
putenv('APP_ENV=testing');
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$mode = $argv[1];
$eventId = $argv[2];
$gateId = $argv[3];
$ticketId = $argv[4];
$actorId = $argv[5];
$assignmentId = $argv[6];
$barrier = $argv[7];
$workerId = $argv[8];
$managerId = $argv[9];
$credential = (string) file_get_contents($barrier.DIRECTORY_SEPARATOR.'credential');

$identity = Mockery::mock(IdentityModuleContract::class);
$identity->shouldReceive('hasPermission')->andReturn(true);
$app->instance(IdentityModuleContract::class, $identity);
$stepUp = Mockery::mock(ActorStepUpVerifierContract::class);
$stepUp->shouldReceive('verifyStepUpForActor')->andReturn(true);
$app->instance(ActorStepUpVerifierContract::class, $stepUp);
$membership = Mockery::mock(OrganisationMembershipAuthority::class);
$membership->shouldReceive('assertActiveMembership')->andReturnNull();
$app->instance(OrganisationMembershipAuthority::class, $membership);
$audit = Mockery::mock(AuditServiceInterface::class);
$audit->shouldReceive('record')->andReturnNull();
$app->instance(AuditServiceInterface::class, $audit);

file_put_contents($barrier.DIRECTORY_SEPARATOR.$workerId.'.ready', (string) getmypid(), LOCK_EX);
$deadline = microtime(true) + 30;
while (! is_file($barrier.DIRECTORY_SEPARATOR.$workerId.'.go')) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Admission race worker timed out waiting for the barrier.');
    }
    usleep(5000);
}

try {
    if ($mode === 'scan') {
        $result = app(EventAdmissionService::class)->validateOnline($eventId, $gateId, $actorId, $credential, (string) Uuid::uuid7());
    } elseif ($mode === 'replace') {
        $result = app(EventAdmissionService::class)->replaceCredential($ticketId, $actorId, (string) Uuid::uuid7(), 'test-step-up', 'race-'.$workerId);
    } elseif ($mode === 'revoke') {
        $result = app(EventAssignmentManagementService::class)->revokeAssignment($assignmentId, $managerId, 'postgres-race-test', (string) Uuid::uuid7());
    } else {
        throw new InvalidArgumentException('Unknown admission race worker mode.');
    }
    echo 'RESULT:'.json_encode(['mode' => $mode, 'result' => $result, 'pid' => getmypid()], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (HttpExceptionInterface $exception) {
    echo 'RESULT:'.json_encode(['mode' => $mode, 'http_status' => $exception->getStatusCode(), 'pid' => getmypid()], JSON_THROW_ON_ERROR).PHP_EOL;
}
