<?php

declare(strict_types=1);

use App\Modules\Events\Application\EventInventoryHoldService;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpKernel\Exception\HttpException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
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
$db = DB::connection('community_services');
$assert($db->getDriverName() === 'pgsql', 'This verification requires PostgreSQL; no other driver is accepted.');
$barrierRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'p25-hold-'.bin2hex(random_bytes(8));
if (! mkdir($barrierRoot, 0700)) {
    throw new RuntimeException('Could not create the local test barrier directory.');
}

$fixtures = [];
$createEvent = static function (int $capacity) use ($db, &$fixtures, $assert): array {
    $eventId = (string) Uuid::uuid7();
    $categoryId = (string) Uuid::uuid7();
    $organisationId = (string) Uuid::uuid7();
    $actorId = (string) Uuid::uuid7();
    $currency = DB::connection('platform')->table('multi_currency.currencies')->where('is_active', true)
        ->orderBy('code')->first(['code', 'decimal_places', 'updated_at']);
    $assert($currency !== null, 'Concurrency verification requires an active currency definition.');
    $assignmentId = (string) Uuid::uuid7();
    $now = now();
    $db->table('community_services.event_drafts')->insert([
        'id' => $eventId, 'organisation_id' => $organisationId, 'created_by' => $actorId,
        'title' => 'P25 PostgreSQL concurrency fixture', 'organiser_name' => 'Test-only',
        'sponsors' => json_encode([], JSON_THROW_ON_ERROR), 'capacity' => $capacity, 'currency_code' => $currency->code,
        'timezone' => 'Africa/Gaborone', 'starts_at' => $now->copy()->addDays(2),
        'ends_at' => $now->copy()->addDays(2)->addHours(2), 'sales_start_at' => $now->copy()->subHour(),
        'sales_end_at' => $now->copy()->addDay(), 'cancellation_policy' => 'test-only',
        'transfer_policy' => 'test-only', 'entry_policy' => 'test-only', 'version' => 1,
        'max_tickets_per_order' => $capacity, 'hold_duration_seconds' => 3600,
        'workflow_state' => 'published', 'sales_state' => 'active', 'published_version' => 1,
        'currency_precision_snapshot' => $currency->decimal_places,
        'currency_definition_updated_at' => $currency->updated_at, 'currency_assignment_id' => $assignmentId,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $db->table('community_services.event_currency_assignments')->insert([
        'id' => $assignmentId, 'event_id' => $eventId, 'organisation_id' => $organisationId,
        'currency_code' => $currency->code, 'precision_snapshot' => $currency->decimal_places,
        'currency_definition_updated_at' => $currency->updated_at, 'assigned_by_actor_id' => $actorId,
        'approval_reference' => 'test-only-fixture', 'policy_version' => 'test-only',
        'assigned_at' => $now, 'superseded_at' => null, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $db->table('community_services.event_draft_categories')->insert([
        'id' => $categoryId, 'event_id' => $eventId, 'name' => 'Test category', 'capacity' => $capacity,
        'price_minor' => 100, 'issued_quantity' => 0, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $fixtures[] = $eventId;

    return ['event' => $eventId, 'category' => $categoryId, 'organisation' => $organisationId, 'actor' => $actorId];
};

$runRace = static function (array $operations, array $fixture, string $label) use ($barrierRoot): array {
    $barrier = $barrierRoot.DIRECTORY_SEPARATOR.$label;
    $processes = [];
    foreach ($operations as $index => $operation) {
        $workerId = (string) Uuid::uuid7();
        $command = [PHP_BINARY, __DIR__.'/P25InventoryHoldWorker.php', $operation['type'], $fixture['event'],
            $fixture['category'], $operation['actor'], $operation['key'] ?? '', $barrier, $workerId];
        $pipes = [];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
            dirname(__DIR__, 2));
        if (! is_resource($process)) {
            throw new RuntimeException('Could not start independent PHP worker process.');
        }
        fclose($pipes[0]);
        $processes[] = ['process' => $process, 'pipes' => $pipes, 'worker' => $workerId];
    }

    $deadline = microtime(true) + 30;
    foreach ($processes as $worker) {
        $ready = $barrier.'.'.$worker['worker'].'.ready';
        while (! is_file($ready)) {
            if (microtime(true) >= $deadline) {
                file_put_contents($barrier.'.go', 'go', LOCK_EX);
                throw new RuntimeException('A worker did not connect and reach the race barrier.');
            }
            usleep(10000);
        }
    }
    $pids = array_map(static fn (array $worker): string => trim((string) file_get_contents($barrier.'.'.$worker['worker'].'.ready')), $processes);
    if (count(array_unique($pids)) !== count($processes)) {
        throw new RuntimeException('Workers did not use distinct PostgreSQL backend sessions.');
    }
    file_put_contents($barrier.'.go', 'go', LOCK_EX);

    $results = [];
    foreach ($processes as $worker) {
        $stdout = stream_get_contents($worker['pipes'][1]);
        $stderr = stream_get_contents($worker['pipes'][2]);
        fclose($worker['pipes'][1]);
        fclose($worker['pipes'][2]);
        $exit = proc_close($worker['process']);
        if ($exit !== 0) {
            throw new RuntimeException('Race worker failed: '.trim((string) $stderr).' '.trim((string) $stdout));
        }
        $result = json_decode((string) $stdout, true, 512, JSON_THROW_ON_ERROR);
        $result['stderr'] = trim((string) $stderr);
        $results[] = $result;
    }

    return $results;
};

$summary = [];
try {
    // Two independent PostgreSQL sessions compete for the one remaining unit.
    $last = $createEvent(1);
    $lastRace = $runRace([
        ['type' => 'hold', 'actor' => (string) Uuid::uuid7(), 'key' => (string) Uuid::uuid7()],
        ['type' => 'hold', 'actor' => (string) Uuid::uuid7(), 'key' => (string) Uuid::uuid7()],
    ], $last, 'last-ticket');
    $assert(count(array_filter($lastRace, static fn (array $row): bool => $row['outcome'] === 'created')) === 1,
        'Exactly one worker must reserve the last ticket.');
    $assert(count(array_filter($lastRace, static fn (array $row): bool => $row['outcome'] === 'error' && $row['status'] === 409)) === 1,
        'The losing last-ticket reservation must be a conflict.');
    $activeQty = (int) $db->table('community_services.event_inventory_holds')->where('event_id', $last['event'])
        ->where('status', 'held')->where('expires_at', '>', now())->sum('quantity');
    $assert($activeQty === 1, 'Last-ticket race must leave exactly one active unit reserved.');
    $currencyLock = $db->table('community_services.event_drafts')->where('id', $last['event'])
        ->first(['currency_locked_at', 'currency_lock_reason']);
    $assert($currencyLock->currency_locked_at !== null && $currencyLock->currency_lock_reason === 'inventory_hold',
        'The concurrent first holds must serialize the event currency/precision lock with the reservation transaction.');
    $summary['last_capacity_race'] = ['sessions' => $lastRace, 'active_quantity' => $activeQty,
        'currency_lock_reason' => $currencyLock->currency_lock_reason, 'currency_locked' => true];

    // Same actor/key/payload replays to one durable hold under concurrent calls.
    $repeat = $createEvent(2);
    $repeatActor = (string) Uuid::uuid7();
    $repeatKey = (string) Uuid::uuid7();
    $replays = $runRace([
        ['type' => 'hold', 'actor' => $repeatActor, 'key' => $repeatKey],
        ['type' => 'hold', 'actor' => $repeatActor, 'key' => $repeatKey],
    ], $repeat, 'same-request');
    $assert(count(array_filter($replays, static fn (array $row): bool => $row['outcome'] === 'created')) === 2,
        'Both identical concurrent requests must return the same successful reservation.');
    $assert(count(array_unique(array_column($replays, 'id'))) === 1, 'Concurrent replay must return one hold ID.');
    $storedRepeats = $db->table('community_services.event_inventory_holds')->where('event_id', $repeat['event'])
        ->where('customer_actor_id', $repeatActor)->where('idempotency_key', $repeatKey)->count();
    $assert($storedRepeats === 1, 'Concurrent repeated request must persist exactly one hold.');
    $mismatchRejected = false;
    try {
        app(EventInventoryHoldService::class)->createHold(
            $repeat['event'], $repeat['category'], $repeatActor, 2, $repeatKey, (string) Uuid::uuid7(),
        );
    } catch (HttpException $exception) {
        $mismatchRejected = $exception->getStatusCode() === 409;
    }
    $assert($mismatchRejected && $storedRepeats === 1, 'A changed payload cannot reuse an idempotency key.');
    $summary['repeated_request_race'] = ['sessions' => $replays, 'durable_holds' => $storedRepeats,
        'changed_payload_rejected' => $mismatchRejected];

    // Expiry and a replacement reservation race; the released unit is reusable once only.
    $expiry = $createEvent(1);
    $initial = app(EventInventoryHoldService::class)->createHold(
        $expiry['event'], $expiry['category'], (string) Uuid::uuid7(), 1, (string) Uuid::uuid7(), (string) Uuid::uuid7(),
    );
    $db->table('community_services.event_inventory_holds')->where('id', $initial['id'])->update(['expires_at' => now()->subSecond()]);
    $expiryRace = $runRace([
        ['type' => 'expire', 'actor' => '', 'key' => ''],
        ['type' => 'hold', 'actor' => (string) Uuid::uuid7(), 'key' => (string) Uuid::uuid7()],
    ], $expiry, 'expiry-release');
    $expiredState = $db->table('community_services.event_inventory_holds')->where('id', $initial['id'])->value('status');
    $activeRows = $db->table('community_services.event_inventory_holds')->where('event_id', $expiry['event'])
        ->where('status', 'held')->where('expires_at', '>', now())->count();
    $activeQty = (int) $db->table('community_services.event_inventory_holds')->where('event_id', $expiry['event'])
        ->where('status', 'held')->where('expires_at', '>', now())->sum('quantity');
    $assert($expiredState === 'expired' && $activeRows === 1 && $activeQty === 1,
        'Expiry/replacement race must expire the old hold and retain exactly one new unit.');
    $summary['expiry_release_race'] = ['sessions' => $expiryRace, 'old_hold' => $expiredState,
        'active_rows' => $activeRows, 'active_quantity' => $activeQty];

    // Fail an audit insert after the reservation insert, prove rollback, then reserve the freed capacity.
    $rollback = $createEvent(1);
    $failedActor = (string) Uuid::uuid7();
    $suffix = bin2hex(random_bytes(5));
    $function = 'community_services.p25_fail_audit_'.$suffix;
    $trigger = 'p25_fail_audit_'.$suffix;
    $db->unprepared("CREATE FUNCTION {$function}() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF NEW.event_id = '{$rollback['event']}'::uuid AND NEW.actor_id = '{$failedActor}'::uuid THEN RAISE EXCEPTION 'P25 forced audit rollback'; END IF; RETURN NEW; END; \$\$");
    $db->unprepared("CREATE TRIGGER {$trigger} BEFORE INSERT ON community_services.event_draft_audit FOR EACH ROW EXECUTE FUNCTION {$function}()");
    $rollbackObserved = false;
    try {
        app(EventInventoryHoldService::class)->createHold(
            $rollback['event'], $rollback['category'], $failedActor, 1, (string) Uuid::uuid7(), (string) Uuid::uuid7(),
        );
    } catch (Throwable) {
        $rollbackObserved = true;
    } finally {
        $db->unprepared("DROP TRIGGER IF EXISTS {$trigger} ON community_services.event_draft_audit");
        $db->unprepared("DROP FUNCTION IF EXISTS {$function}()");
    }
    $afterRollback = $db->table('community_services.event_inventory_holds')->where('event_id', $rollback['event'])->count();
    $auditAfterRollback = $db->table('community_services.event_draft_audit')->where('event_id', $rollback['event'])
        ->where('action', 'inventory_hold_created')->count();
    $assert($rollbackObserved && $afterRollback === 0 && $auditAfterRollback === 0,
        'A database audit failure must roll back both the hold and its audit row.');
    $recovered = app(EventInventoryHoldService::class)->createHold(
        $rollback['event'], $rollback['category'], (string) Uuid::uuid7(), 1, (string) Uuid::uuid7(), (string) Uuid::uuid7(),
    );
    $assert($recovered['status'] === 'held', 'Capacity must be reservable after the failed transaction rolls back.');
    $summary['rollback_recovery'] = ['rollback_observed' => $rollbackObserved, 'holds_after_rollback' => $afterRollback,
        'audit_rows_after_rollback' => $auditAfterRollback, 'recovery_hold_id' => $recovered['id']];

    echo json_encode(['driver' => $db->getDriverName(), 'database' => config('database.connections.community_services.database'),
        'scenarios' => $summary], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    foreach ($fixtures as $eventId) {
        $db->table('community_services.event_inventory_holds')->where('event_id', $eventId)->delete();
        $db->table('community_services.event_draft_version_contributors')->where('event_id', $eventId)->delete();
        $db->table('community_services.event_draft_categories')->where('event_id', $eventId)->delete();
        $db->table('community_services.event_draft_audit')->where('event_id', $eventId)->delete();
        $db->table('community_services.event_currency_review_issues')->where('event_id', $eventId)->delete();
        $db->table('community_services.event_currency_assignments')->where('event_id', $eventId)->delete();
        $db->table('community_services.event_drafts')->where('id', $eventId)->delete();
    }
    foreach (glob($barrierRoot.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
        @unlink($path);
    }
    @rmdir($barrierRoot);
}
