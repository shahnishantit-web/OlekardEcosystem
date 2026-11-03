<?php

declare(strict_types=1);

use App\Modules\Events\Application\EventInventoryHoldService;
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

[$script, $operation, $eventId, $categoryId, $actorId, $key, $barrier, $workerId] = $argv;
$connection = DB::connection('community_services');
$pid = (int) $connection->selectOne('select pg_backend_pid() as pid')->pid;
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
    if ($operation === 'hold') {
        $hold = app(EventInventoryHoldService::class)->createHold(
            $eventId, $categoryId, $actorId, 1, $key, $workerId,
        );
        echo json_encode(['outcome' => 'created', 'id' => $hold['id'], 'pid' => $pid], JSON_THROW_ON_ERROR);
        exit(0);
    }
    if ($operation === 'expire') {
        $count = app(EventInventoryHoldService::class)->expireDueHolds($eventId);
        echo json_encode(['outcome' => 'expired', 'count' => $count, 'pid' => $pid], JSON_THROW_ON_ERROR);
        exit(0);
    }
    throw new RuntimeException('Unknown race operation.');
} catch (Throwable $exception) {
    $status = method_exists($exception, 'getStatusCode') ? $exception->getStatusCode() : null;
    echo json_encode(['outcome' => 'error', 'status' => $status, 'type' => $exception::class, 'pid' => $pid], JSON_THROW_ON_ERROR);
    exit(0);
}
