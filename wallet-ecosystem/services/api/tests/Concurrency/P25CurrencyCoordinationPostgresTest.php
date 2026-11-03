<?php

declare(strict_types=1);

use App\Modules\Events\Application\EventInventoryHoldService;
use App\Modules\MultiCurrency\Application\Services\FxRateAdminService;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpKernel\Exception\HttpException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
putenv('APP_ENV=testing');
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$app->instance(AuditServiceInterface::class, new class implements AuditServiceInterface
{
    public function record(AuditEventData $event): void {}
});

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$platform = DB::connection('platform');
$events = DB::connection('community_services');
$assert($platform->getDriverName() === 'pgsql' && $events->getDriverName() === 'pgsql',
    'Currency coordination acceptance requires PostgreSQL on both module connections.');
$topology = [];
foreach (['platform' => $platform, 'community_services' => $events] as $name => $connection) {
    $identity = $connection->selectOne('SELECT current_database() AS db, inet_server_addr()::text AS host, inet_server_port() AS port, pg_backend_pid() AS pid');
    $topology[$name] = ['database' => $identity->db, 'server' => $identity->host, 'port' => (int) $identity->port, 'pid' => (int) $identity->pid];
}
$assert($topology['platform']['database'] === $topology['community_services']['database']
    && $topology['platform']['server'] === $topology['community_services']['server']
    && $topology['platform']['port'] === $topology['community_services']['port'],
    'Named module connections must target the same PostgreSQL primary/database for advisory-lock coordination.');

$currency = $platform->table('multi_currency.currencies')->where('is_active', true)->orderBy('code')
    ->first(['code', 'decimal_places', 'is_active', 'updated_at']);
$assert($currency !== null, 'Currency coordination acceptance needs an active test currency.');
$currencyCode = (string) $currency->code;
$originalPrecision = (int) $currency->decimal_places;
$originalActive = (bool) $currency->is_active;
$originalUpdatedAt = (string) $currency->updated_at;
$barrierRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'p25-currency-'.bin2hex(random_bytes(8));
$assert(mkdir($barrierRoot, 0700), 'Could not create the temporary coordination barrier directory.');
$fixtures = [];
$workers = [];

$makeEvent = static function () use ($events, $currency, &$fixtures): array {
    $eventId = (string) Uuid::uuid7();
    $categoryId = (string) Uuid::uuid7();
    $organisationId = (string) Uuid::uuid7();
    $actorId = (string) Uuid::uuid7();
    $assignmentId = (string) Uuid::uuid7();
    $now = now();
    $events->table('community_services.event_drafts')->insert([
        'id' => $eventId, 'organisation_id' => $organisationId, 'created_by' => $actorId,
        'title' => 'P25 currency lock race', 'event_format' => 'physical', 'venue_country_code' => 'BW',
        'organiser_name' => 'Test only', 'sponsors' => json_encode([], JSON_THROW_ON_ERROR), 'capacity' => 1,
        'currency_code' => $currency->code, 'currency_precision_snapshot' => $currency->decimal_places,
        'currency_definition_updated_at' => $currency->updated_at, 'currency_assignment_id' => $assignmentId,
        'timezone' => 'Africa/Gaborone', 'starts_at' => $now->copy()->addDays(2),
        'ends_at' => $now->copy()->addDays(2)->addHours(2), 'sales_start_at' => $now->copy()->subHour(),
        'sales_end_at' => $now->copy()->addDay(), 'cancellation_policy' => 'test-only',
        'transfer_policy' => 'test-only', 'entry_policy' => 'test-only', 'version' => 1,
        'max_tickets_per_order' => 1, 'hold_duration_seconds' => 3600, 'workflow_state' => 'published',
        'sales_state' => 'active', 'published_version' => 1, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $events->table('community_services.event_currency_assignments')->insert([
        'id' => $assignmentId, 'event_id' => $eventId, 'organisation_id' => $organisationId,
        'currency_code' => $currency->code, 'precision_snapshot' => $currency->decimal_places,
        'currency_definition_updated_at' => $currency->updated_at, 'assigned_by_actor_id' => $actorId,
        'approval_reference' => 'test-only', 'policy_version' => 'test-only', 'assigned_at' => $now,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $events->table('community_services.event_draft_categories')->insert([
        'id' => $categoryId, 'event_id' => $eventId, 'name' => 'General', 'capacity' => 1,
        'price_minor' => 100, 'issued_quantity' => 0, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $fixtures[] = $eventId;

    return ['event' => $eventId, 'category' => $categoryId, 'actor' => $actorId];
};

$startWorker = static function (string $operation, array $fixture, string $key, string $mutation = '') use ($barrierRoot, $currencyCode, &$workers): array {
    $workerId = (string) Uuid::uuid7();
    $command = [PHP_BINARY, __DIR__.'/P25CurrencyCoordinationWorker.php', $operation, $fixture['event'],
        $fixture['category'], $fixture['actor'], $key, $currencyCode, $barrierRoot, $workerId, $mutation];
    $pipes = [];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
    if (! is_resource($process)) {
        throw new RuntimeException('Could not start a currency coordination worker.');
    }
    fclose($pipes[0]);
    $worker = ['process' => $process, 'pipes' => $pipes, 'id' => $workerId,
        'path' => $barrierRoot.DIRECTORY_SEPARATOR.$workerId];
    $workers[] = $worker;
    $deadline = microtime(true) + 30;
    while (! is_file($worker['path'].'.ready')) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Currency coordination worker did not reach its ready barrier.');
        }
        usleep(10000);
    }
    $worker['pid'] = (int) file_get_contents($worker['path'].'.ready');

    return $worker;
};
$release = static function (array $worker, string $marker): void {
    file_put_contents($worker['path'].'.'.$marker, 'go', LOCK_EX);
};
$awaitAdvisoryWait = static function (int $pid) use ($platform): void {
    $deadline = microtime(true) + 20;
    do {
        $activity = $platform->selectOne(
            'SELECT wait_event_type, wait_event FROM pg_stat_activity WHERE pid = ?', [$pid],
        );
        if ($activity !== null && $activity->wait_event_type === 'Lock' && $activity->wait_event === 'advisory') {
            return;
        }
        usleep(10000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('Worker did not wait on the shared currency advisory lock.');
};
$finishWorker = static function (array $worker) use (&$workers): array {
    $deadline = microtime(true) + 40;
    $exitCode = null;
    do {
        $status = proc_get_status($worker['process']);
        if (! $status['running']) {
            $exitCode = (int) $status['exitcode'];
            break;
        }
        usleep(10000);
    } while (microtime(true) < $deadline);
    if (($status['running'] ?? false) === true) {
        proc_terminate($worker['process']);
        throw new RuntimeException('Currency coordination worker exceeded the deadlock/retry timeout.');
    }
    $stdout = stream_get_contents($worker['pipes'][1]);
    $stderr = stream_get_contents($worker['pipes'][2]);
    fclose($worker['pipes'][1]);
    fclose($worker['pipes'][2]);
    $closedExitCode = proc_close($worker['process']);
    foreach ($workers as $index => $startedWorker) {
        if ($startedWorker['id'] === $worker['id']) {
            $workers[$index]['process'] = null;
            $workers[$index]['pipes'] = [];
            break;
        }
    }
    $exit = $exitCode !== null && $exitCode >= 0 ? $exitCode : $closedExitCode;
    if ($exit !== 0) {
        throw new RuntimeException('Currency coordination worker failed: '.trim((string) $stderr).' '.trim((string) $stdout));
    }
    $result = json_decode((string) $stdout, true, 512, JSON_THROW_ON_ERROR);
    $result['stderr'] = trim((string) $stderr);

    return $result;
};
$restoreCurrency = static function () use ($platform, $currencyCode, $originalPrecision, $originalActive, $originalUpdatedAt): void {
    $platform->beginTransaction();
    try {
        FxRateAdminService::lock('currency:'.$currencyCode, $platform);
        $platform->table('multi_currency.currencies')->where('code', $currencyCode)->update([
            'decimal_places' => $originalPrecision, 'is_active' => $originalActive, 'updated_at' => $originalUpdatedAt,
        ]);
        $platform->commit();
    } catch (Throwable $exception) {
        if ($platform->transactionLevel() > 0) {
            $platform->rollBack();
        }
        throw $exception;
    }
};

$summary = ['topology' => $topology, 'shared_lock_key' => 'currency:'.$currencyCode];
try {
    // Raw SQL writer-first precision update: the database trigger must acquire the
    // shared key even when the writer does not call FxRateAdminService::lock().
    // The hold blocks until commit, then records drift and creates no hold.
    $precisionCase = $makeEvent();
    $platform->beginTransaction();
    $platform->table('multi_currency.currencies')->where('code', $currencyCode)->update([
        'decimal_places' => $originalPrecision + 1, 'updated_at' => now(),
    ]);
    $precisionHold = $startWorker('hold', $precisionCase, 'precision-first-'.Uuid::uuid7());
    $release($precisionHold, 'go');
    $awaitAdvisoryWait($precisionHold['pid']);
    $platform->commit();
    $precisionResult = $finishWorker($precisionHold);
    $precisionIssue = $events->table('community_services.event_currency_review_issues')->where('event_id', $precisionCase['event'])
        ->whereNull('resolved_at')->value('reason_code');
    $precisionHolds = $events->table('community_services.event_inventory_holds')->where('event_id', $precisionCase['event'])->count();
    $precisionSales = $events->table('community_services.event_drafts')->where('id', $precisionCase['event'])->value('sales_state');
    $assert(($precisionResult['status'] ?? null) === 409 && $precisionIssue === 'CURRENCY_PRECISION_CHANGED'
        && $precisionHolds === 0 && $precisionSales === 'paused',
        'Writer-first precision update must reject the hold and persist review/pause evidence.');
    $summary['writer_first_precision'] = ['worker' => $precisionResult, 'issue' => $precisionIssue,
        'holds' => $precisionHolds, 'sales_state' => $precisionSales];
    $restoreCurrency();

    // Hold-first status update: the currency writer waits only on the currency lock;
    // it never needs an event row, so the documented lock order has no cycle.
    $holdCase = $makeEvent();
    $events->table('community_services.event_draft_categories')->where('id', $holdCase['category'])->update(['capacity' => 2]);
    $holdFirst = $startWorker('hold-first', $holdCase, 'hold-first-'.Uuid::uuid7());
    $release($holdFirst, 'go');
    $deadline = microtime(true) + 20;
    while (! is_file($holdFirst['path'].'.locked')) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Hold-first worker did not acquire event then currency locks.');
        }
        usleep(10000);
    }
    $currencyWriter = $startWorker('currency-write', $holdCase, 'unused', 'deactivate');
    $release($currencyWriter, 'go');
    $awaitAdvisoryWait($currencyWriter['pid']);
    $release($holdFirst, 'commit');
    $holdResult = $finishWorker($holdFirst);
    $release($currencyWriter, 'commit');
    $currencyResult = $finishWorker($currencyWriter);
    $holdRows = $events->table('community_services.event_inventory_holds')->where('event_id', $holdCase['event'])->count();
    $assert($holdResult['outcome'] === 'hold_committed' && $currencyResult['outcome'] === 'currency_committed' && $holdRows === 1,
        'Hold-first and currency writer must both complete in lock order without deadlock.');
    try {
        app(EventInventoryHoldService::class)->createHold(
            $holdCase['event'], $holdCase['category'], $holdCase['actor'], 1,
            'after-deactivation-'.Uuid::uuid7(), (string) Uuid::uuid7(),
        );
        $assert(false, 'A new hold after deactivation must be denied.');
    } catch (HttpException $exception) {
        $assert($exception->getStatusCode() === 409, 'Deactivated currency hold must return conflict.');
    }
    $reviewIssue = $events->table('community_services.event_currency_review_issues')->where('event_id', $holdCase['event'])
        ->whereNull('resolved_at')->value('reason_code');
    $salesState = $events->table('community_services.event_drafts')->where('id', $holdCase['event'])->value('sales_state');
    $holdRowsAfterDrift = $events->table('community_services.event_inventory_holds')->where('event_id', $holdCase['event'])->count();
    $assert($reviewIssue === 'CURRENCY_INACTIVE' && $salesState === 'paused' && $holdRowsAfterDrift === 1,
        'Post-deactivation hold must preserve the existing hold, record review, and pause future sales. '
            .json_encode(['issue' => $reviewIssue, 'sales_state' => $salesState, 'holds' => $holdRowsAfterDrift], JSON_THROW_ON_ERROR));
    $summary['hold_first_status'] = ['hold' => $holdResult, 'writer' => $currencyResult,
        'existing_holds' => $holdRows, 'issue' => $reviewIssue, 'sales_state' => $salesState];
    $restoreCurrency();

    // A writer rollback releases its advisory lock without changing the definition;
    // the blocked hold then re-reads the committed active snapshot and succeeds.
    $rollbackCase = $makeEvent();
    $platform->beginTransaction();
    FxRateAdminService::lock('currency:'.$currencyCode, $platform);
    $platform->table('multi_currency.currencies')->where('code', $currencyCode)->update(['is_active' => false, 'updated_at' => now()]);
    $retryKey = 'rollback-retry-'.Uuid::uuid7();
    $retryHold = $startWorker('hold', $rollbackCase, $retryKey);
    $release($retryHold, 'go');
    $awaitAdvisoryWait($retryHold['pid']);
    $platform->rollBack();
    $retryResult = $finishWorker($retryHold);
    $replay = app(EventInventoryHoldService::class)->createHold(
        $rollbackCase['event'], $rollbackCase['category'], $rollbackCase['actor'], 1, $retryKey, (string) Uuid::uuid7(),
    );
    $retryRows = $events->table('community_services.event_inventory_holds')->where('event_id', $rollbackCase['event'])->count();
    $assert($retryResult['outcome'] === 'hold_created' && $replay['id'] === $retryResult['id'] && $retryRows === 1,
        'After a currency-writer rollback, hold retry and identical replay must leave one durable hold.');
    $summary['writer_rollback_retry'] = ['worker' => $retryResult, 'replayed_id' => $replay['id'], 'durable_holds' => $retryRows];

    echo json_encode(['database' => $topology['platform']['database'], 'scenarios' => $summary], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), PHP_EOL;
} finally {
    if ($platform->transactionLevel() > 0) {
        $platform->rollBack();
    }
    foreach ($workers as $worker) {
        if (is_resource($worker['process'] ?? null)) {
            file_put_contents($worker['path'].'.go', 'go', LOCK_EX);
            file_put_contents($worker['path'].'.commit', 'go', LOCK_EX);
        }
    }
    foreach ($workers as $worker) {
        if (! is_resource($worker['process'] ?? null)) {
            continue;
        }
        $deadline = microtime(true) + 5;
        do {
            $status = proc_get_status($worker['process']);
            if (! $status['running']) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        if (($status['running'] ?? false) === true) {
            proc_terminate($worker['process']);
        }
        foreach ($worker['pipes'] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($worker['process']);
    }
    $restoreCurrency();
    foreach ($fixtures as $eventId) {
        $events->table('community_services.event_inventory_holds')->where('event_id', $eventId)->delete();
        $events->table('community_services.event_draft_version_contributors')->where('event_id', $eventId)->delete();
        $events->table('community_services.event_draft_categories')->where('event_id', $eventId)->delete();
        $events->table('community_services.event_draft_audit')->where('event_id', $eventId)->delete();
        $events->table('community_services.event_currency_review_issues')->where('event_id', $eventId)->delete();
        $events->table('community_services.event_currency_assignments')->where('event_id', $eventId)->delete();
        $events->table('community_services.event_drafts')->where('id', $eventId)->delete();
    }
    foreach (glob($barrierRoot.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    rmdir($barrierRoot);
}
