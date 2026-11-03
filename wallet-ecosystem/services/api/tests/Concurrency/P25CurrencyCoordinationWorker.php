<?php

declare(strict_types=1);

use App\Modules\Events\Application\EventCurrencyAssignmentService;
use App\Modules\Events\Application\EventInventoryHoldService;
use App\Modules\MultiCurrency\Application\Services\FxRateAdminService;
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

[$script, $operation, $eventId, $categoryId, $actorId, $key, $currencyCode, $barrier, $workerId, $mutation] = $argv;
$dbName = $operation === 'currency-write' ? 'platform' : 'community_services';
$connection = DB::connection($dbName);
$pid = (int) $connection->selectOne('SELECT pg_backend_pid() AS pid')->pid;
$workerPath = $barrier.DIRECTORY_SEPARATOR.$workerId;
file_put_contents($workerPath.'.ready', (string) $pid, LOCK_EX);

$waitFor = static function (string $path): void {
    $deadline = microtime(true) + 40;
    while (! is_file($path)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for coordination barrier.');
        }
        usleep(10000);
    }
};

try {
    $waitFor($workerPath.'.go');
    if ($operation === 'hold') {
        $hold = app(EventInventoryHoldService::class)->createHold(
            $eventId, $categoryId, $actorId, 1, $key, $workerId,
        );
        echo json_encode(['outcome' => 'hold_created', 'id' => $hold['id'], 'pid' => $pid], JSON_THROW_ON_ERROR);
        exit(0);
    }

    if ($operation === 'hold-first') {
        $connection->beginTransaction();
        try {
            $connection->table('community_services.event_drafts')->where('id', $eventId)->lockForUpdate()->first();
            app(EventCurrencyAssignmentService::class)->lockForFirstObligation($eventId, 'inventory_hold');
            $hold = app(EventInventoryHoldService::class)->createHold(
                $eventId, $categoryId, $actorId, 1, $key, $workerId,
            );
            file_put_contents($workerPath.'.locked', (string) $pid, LOCK_EX);
            $waitFor($workerPath.'.commit');
            $connection->commit();
            echo json_encode(['outcome' => 'hold_committed', 'id' => $hold['id'], 'pid' => $pid], JSON_THROW_ON_ERROR);
            exit(0);
        } catch (Throwable $exception) {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            throw $exception;
        }
    }

    if ($operation === 'currency-write') {
        $connection->beginTransaction();
        try {
            FxRateAdminService::lock('currency:'.strtoupper($currencyCode), $connection);
            $changes = match ($mutation) {
                'deactivate' => ['is_active' => false, 'updated_at' => now()],
                'activate' => ['is_active' => true, 'updated_at' => now()],
                'precision' => ['decimal_places' => (int) $key, 'updated_at' => now()],
                default => throw new RuntimeException('Unknown currency mutation.'),
            };
            $connection->table('multi_currency.currencies')->where('code', strtoupper($currencyCode))->update($changes);
            file_put_contents($workerPath.'.locked', (string) $pid, LOCK_EX);
            $waitFor($workerPath.'.commit');
            $connection->commit();
            echo json_encode(['outcome' => 'currency_committed', 'pid' => $pid, 'mutation' => $mutation], JSON_THROW_ON_ERROR);
            exit(0);
        } catch (Throwable $exception) {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            throw $exception;
        }
    }

    throw new RuntimeException('Unknown coordination operation.');
} catch (Throwable $exception) {
    $status = method_exists($exception, 'getStatusCode') ? $exception->getStatusCode() : null;
    echo json_encode(['outcome' => 'error', 'status' => $status, 'type' => $exception::class,
        'message' => $exception->getMessage(), 'pid' => $pid], JSON_THROW_ON_ERROR);
    exit(0);
}
