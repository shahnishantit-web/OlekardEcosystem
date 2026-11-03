<?php

declare(strict_types=1);

use App\Modules\Events\Application\AdmissionCredentialVault;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
putenv('APP_ENV=testing');
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = DB::connection('community_services');
$payment = DB::connection('payment');
$assert = static function (bool $ok, string $message): void {
    if (! $ok) {
        throw new RuntimeException($message);
    }
};
$identity = $db->selectOne('SELECT current_database() AS name, pg_backend_pid() AS pid');
$assert($db->getDriverName() === 'pgsql' && $identity->name === 'wallet_ecosystem_bootstrap_20261028',
    'Admission concurrency evidence must run only on the explicitly isolated PostgreSQL database.');
$assert($payment->getDriverName() === 'pgsql', 'Payment refund-state checks must use PostgreSQL too.');
$eventId = (string) Uuid::uuid7();
$gateId = (string) Uuid::uuid7();
$organisationId = (string) Uuid::uuid7();
$ownerId = (string) Uuid::uuid7();
$operatorId = (string) Uuid::uuid7();
$managerId = (string) Uuid::uuid7();
$grantorId = (string) Uuid::uuid7();
$eventGrantId = (string) Uuid::uuid7();
$gateGrantId = (string) Uuid::uuid7();
$categoryId = (string) Uuid::uuid7();
$holdId = (string) Uuid::uuid7();
$orderId = (string) Uuid::uuid7();
$quoteId = (string) Uuid::uuid7();
$lineId = (string) Uuid::uuid7();
$ticketId = (string) Uuid::uuid7();
$paymentIntentId = (string) Uuid::uuid7();
$now = now();
$currency = DB::connection('platform')->table('multi_currency.currencies')->where('is_active', true)->orderBy('code')->first(['code', 'decimal_places', 'updated_at']);
$assert($currency !== null, 'An active currency fixture is required.');
$db->table('community_services.event_drafts')->insert([
    'id' => $eventId, 'organisation_id' => $organisationId, 'created_by' => $ownerId,
    'title' => 'P25 admission concurrency fixture', 'event_format' => 'physical', 'venue_country_code' => 'BW',
    'organiser_name' => 'test-only', 'sponsors' => json_encode([], JSON_THROW_ON_ERROR), 'capacity' => 1,
    'currency_code' => $currency->code, 'currency_precision_snapshot' => $currency->decimal_places,
    'currency_definition_updated_at' => $currency->updated_at, 'currency_assignment_id' => (string) Uuid::uuid7(),
    'timezone' => 'Africa/Gaborone', 'starts_at' => $now->copy()->addDays(2), 'ends_at' => $now->copy()->addDays(2)->addHours(2),
    'sales_start_at' => $now->copy()->subHour(), 'sales_end_at' => $now->copy()->addDay(),
    'cancellation_policy' => 'test-only', 'transfer_policy' => 'test-only', 'entry_policy' => 'test-only',
    'version' => 1, 'max_tickets_per_order' => 1, 'hold_duration_seconds' => 3600,
    'workflow_state' => 'published', 'sales_state' => 'active', 'published_version' => 1,
    'created_at' => $now, 'updated_at' => $now,
]);
$db->table('community_services.event_gates')->insert([
    'id' => $gateId, 'organisation_id' => $organisationId, 'event_id' => $eventId,
    'name' => 'Race gate', 'status' => 'active', 'created_by_actor_id' => $managerId, 'created_at' => $now, 'updated_at' => $now,
]);
foreach ([[$eventGrantId, 'event', $eventId, null, $managerId, 'events.gate.assignment.manage'],
    [$gateGrantId, 'gate', $gateId, $gateId, $operatorId, 'events.gate.validate']] as [$grantId, $scopeType, $scopeId, $scopeGateId, $actor, $permission]) {
    $db->table('community_services.event_authority_assignments')->insert([
        'id' => $grantId, 'scope_type' => $scopeType, 'scope_id' => $scopeId, 'organisation_id' => $organisationId,
        'event_id' => $eventId, 'gate_id' => $scopeGateId, 'actor_id' => $actor, 'permission_slug' => $permission,
        'granted_by_actor_id' => $grantorId, 'granted_at' => $now, 'created_at' => $now, 'updated_at' => $now,
    ]);
}
$db->table('community_services.event_inventory_holds')->insert([
    'id' => $holdId, 'event_id' => $eventId, 'category_id' => $categoryId, 'category_name' => 'General',
    'organisation_id' => $organisationId, 'customer_actor_id' => $ownerId, 'quantity' => 1,
    'idempotency_key' => 'p25-admission-race-hold', 'request_hash' => hash('sha256', $holdId), 'status' => 'consumed',
    'expires_at' => $now->copy()->addHour(), 'created_at' => $now, 'updated_at' => $now,
]);
$snapshot = json_encode(['test_only' => true], JSON_THROW_ON_ERROR);
$db->table('community_services.event_orders')->insert([
    'id' => $orderId, 'quote_id' => $quoteId, 'event_id' => $eventId, 'organisation_id' => $organisationId,
    'purchaser_actor_id' => $ownerId, 'idempotency_key' => 'p25-admission-race-order',
    'request_hash' => hash('sha256', $orderId), 'line_snapshot' => $snapshot, 'pricing_snapshot' => $snapshot,
    'confirmation_hash' => hash('sha256', $snapshot), 'subtotal_minor' => 1000, 'fees_minor' => 0, 'taxes_minor' => 0,
    'total_minor' => 1000, 'currency_code' => $currency->code, 'currency_precision' => $currency->decimal_places,
    'seller_merchant_reference' => 'test-only', 'fee_policy_version' => 'test-only', 'tax_policy_version' => 'test-only',
    'order_state' => 'issued', 'payment_state' => 'confirmed', 'accounting_state' => 'verified', 'fulfilment_state' => 'issued',
    'payment_intent_id' => $paymentIntentId, 'currency_locked_at' => $now, 'created_at' => $now, 'updated_at' => $now,
]);
$db->table('community_services.event_order_lines')->insert([
    'id' => $lineId, 'order_id' => $orderId, 'hold_id' => $holdId, 'category_id' => $categoryId,
    'category_name' => 'General', 'quantity' => 1, 'unit_price_minor' => 1000, 'line_subtotal_minor' => 1000, 'created_at' => $now,
]);
$credential = app(AdmissionCredentialVault::class)->create();
$db->table('community_services.event_tickets')->insert([
    'id' => $ticketId, 'order_id' => $orderId, 'order_line_id' => $lineId, 'entitlement_number' => 1,
    'event_id' => $eventId, 'organisation_id' => $organisationId, 'owner_actor_id' => $ownerId,
    'category_id' => $categoryId, 'category_name' => 'General', 'credential_hash' => $credential['hash'],
    'credential_encrypted' => $credential['encrypted'], 'status' => 'valid', 'issued_at' => $now, 'created_at' => $now, 'updated_at' => $now,
]);

$runRace = static function (array $modes) use ($eventId, $gateId, $ticketId, $operatorId, $ownerId, $credential, $gateGrantId, $managerId): array {
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'p25-admission-race-'.bin2hex(random_bytes(8));
    if (! mkdir($dir, 0700)) {
        throw new RuntimeException('Could not create race barrier.');
    }
    file_put_contents($dir.DIRECTORY_SEPARATOR.'credential', $credential['plaintext'], LOCK_EX);
    chmod($dir.DIRECTORY_SEPARATOR.'credential', 0600);
    $workers = [];
    try {
        foreach ($modes as $index => $mode) {
            $workerId = (string) Uuid::uuid7();
            $actorId = $mode === 'replace' ? $ownerId : $operatorId;
            $command = [PHP_BINARY, __DIR__.'/P25AdmissionRaceWorker.php', $mode, $eventId, $gateId, $ticketId,
                $actorId, $gateGrantId, $dir, $workerId, $managerId];
            $pipes = [];
            $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
            if (! is_resource($process)) {
                throw new RuntimeException('Could not start PostgreSQL race worker.');
            }
            fclose($pipes[0]);
            $workers[] = ['process' => $process, 'pipes' => $pipes, 'id' => $workerId, 'index' => $index];
        }
        $deadline = microtime(true) + 30;
        foreach ($workers as $worker) {
            while (! is_file($dir.DIRECTORY_SEPARATOR.$worker['id'].'.ready')) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('Race worker did not reach start barrier.');
                }
                usleep(5000);
            }
        }
        foreach ($workers as $worker) {
            file_put_contents($dir.DIRECTORY_SEPARATOR.$worker['id'].'.go', 'go', LOCK_EX);
        }
        $results = [];
        foreach ($workers as $worker) {
            $stdout = stream_get_contents($worker['pipes'][1]);
            $stderr = stream_get_contents($worker['pipes'][2]);
            fclose($worker['pipes'][1]);
            fclose($worker['pipes'][2]);
            if (proc_close($worker['process']) !== 0) {
                throw new RuntimeException('Admission worker failed: '.$stderr);
            }
            $position = strrpos($stdout, 'RESULT:');
            if ($position === false) {
                throw new RuntimeException('Admission worker produced no result: '.$stderr.$stdout);
            }
            $results[] = json_decode(substr($stdout, $position + 7), true, 512, JSON_THROW_ON_ERROR);
        }

        return $results;
    } finally {
        foreach (glob($dir.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($dir);
    }
};

try {
    $scanResults = $runRace(['scan', 'scan']);
    $assert($db->table('community_services.event_attendance')->where('ticket_id', $ticketId)->count() === 1,
        'Concurrent scans must admit one ticket at most once.');
    $assert($db->table('community_services.event_attendance_audit')->where('ticket_id', $ticketId)->count() === 1,
        'Admission and audit must commit exactly once.');
    $assert(count(array_filter($scanResults, static fn (array $r): bool => ($r['result']['status'] ?? null) === 'admitted')) === 1,
        'Exactly one scan must receive the admitted result.');

    $db->table('community_services.event_attendance_audit')->where('ticket_id', $ticketId)->delete();
    $db->table('community_services.event_attendance')->where('ticket_id', $ticketId)->delete();
    $db->table('community_services.event_tickets')->where('id', $ticketId)->update([
        'credential_hash' => $credential['hash'], 'credential_encrypted' => $credential['encrypted'], 'updated_at' => now(),
    ]);
    $raceResults = $runRace(['scan', 'revoke']);
    $revokedAt = $db->table('community_services.event_authority_assignments')->where('id', $gateGrantId)->value('revoked_at');
    $attendance = $db->table('community_services.event_attendance')->where('ticket_id', $ticketId)->first();
    $assert($revokedAt !== null, 'Revocation worker must durably revoke the gate assignment.');
    $assert($attendance === null || $attendance->admitted_at <= $revokedAt,
        'Admission may commit before revocation wins, but never after revocation commit.');

    // Reset the explicit development assignment fixture, then race an online scan against credential rotation.
    $db->table('community_services.event_attendance_audit')->where('ticket_id', $ticketId)->delete();
    $db->table('community_services.event_attendance')->where('ticket_id', $ticketId)->delete();
    $db->table('community_services.event_ticket_security_audit')->where('ticket_id', $ticketId)->delete();
    $db->table('community_services.event_tickets')->where('id', $ticketId)->update([
        'credential_hash' => $credential['hash'], 'credential_encrypted' => $credential['encrypted'], 'status' => 'valid', 'updated_at' => now(),
    ]);
    $db->table('community_services.event_authority_assignments')->where('id', $gateGrantId)->update([
        'revoked_at' => null, 'revoked_by_actor_id' => null, 'updated_at' => now(),
    ]);
    $rotationResults = $runRace(['scan', 'replace']);
    $attendanceAfterRotationRace = $db->table('community_services.event_attendance')->where('ticket_id', $ticketId)->first();
    $ticketHashAfterRace = $db->table('community_services.event_tickets')->where('id', $ticketId)->value('credential_hash');
    $assert(($attendanceAfterRotationRace !== null && $ticketHashAfterRace === $credential['hash'])
        || ($attendanceAfterRotationRace === null && $ticketHashAfterRace !== $credential['hash']),
        'Scan and replacement must serialize: admission keeps the old secret, while replacement invalidates it before scan.');

    // Explicit scan-first ordering: a committed admission survives later revocation.
    $db->table('community_services.event_attendance_audit')->where('ticket_id', $ticketId)->delete();
    $db->table('community_services.event_attendance')->where('ticket_id', $ticketId)->delete();
    $db->table('community_services.event_ticket_security_audit')->where('ticket_id', $ticketId)->delete();
    $db->table('community_services.event_tickets')->where('id', $ticketId)->update([
        'credential_hash' => $credential['hash'], 'credential_encrypted' => $credential['encrypted'], 'status' => 'valid', 'updated_at' => now(),
    ]);
    $db->table('community_services.event_authority_assignments')->where('id', $gateGrantId)->update([
        'revoked_at' => null, 'revoked_by_actor_id' => null, 'updated_at' => now(),
    ]);
    $scanFirst = $runRace(['scan']);
    $revokeSecond = $runRace(['revoke']);
    $admittedBeforeRevocation = $db->table('community_services.event_attendance')->where('ticket_id', $ticketId)->first();
    $revokedAfterAdmission = $db->table('community_services.event_authority_assignments')->where('id', $gateGrantId)->value('revoked_at');
    $assert(($scanFirst[0]['result']['status'] ?? null) === 'admitted' && $admittedBeforeRevocation !== null
        && $admittedBeforeRevocation->admitted_at <= $revokedAfterAdmission,
        'Scan-first must durably admit before later assignment revocation.');

    // Explicit scan-first replacement ordering: once admitted, credential rotation is denied.
    $replaceAfterAdmission = $runRace(['replace']);
    $assert(($replaceAfterAdmission[0]['http_status'] ?? null) === 409
        && $db->table('community_services.event_tickets')->where('id', $ticketId)->value('credential_hash') === $credential['hash'],
        'Admission committed first must prevent later credential replacement.');

    // Reset to unadmitted with a live assignment, then race two distinct replacement requests.
    $db->table('community_services.event_attendance_audit')->where('ticket_id', $ticketId)->delete();
    $db->table('community_services.event_attendance')->where('ticket_id', $ticketId)->delete();
    $db->table('community_services.event_ticket_security_audit')->where('ticket_id', $ticketId)->delete();
    $db->table('community_services.event_authority_assignments')->where('id', $gateGrantId)->update([
        'revoked_at' => null, 'revoked_by_actor_id' => null, 'updated_at' => now(),
    ]);
    $replacementResults = $runRace(['replace', 'replace']);
    $rotations = count(array_filter($replacementResults, static fn (array $r): bool => ($r['result']['status'] ?? null) === 'rotated'));
    $assert($rotations === 1 && $db->table('community_services.event_ticket_security_audit')->where('ticket_id', $ticketId)->count() === 1,
        'Concurrent credential replacements must consume a proof once and leave one rotation audit.');

    echo json_encode(['status' => 'PASS', 'database' => $identity->name, 'worker_pids' => array_column($scanResults, 'pid'),
        'concurrent_scan_admissions' => 1, 'concurrent_scan_audits' => 1, 'scan_revoke_results' => $raceResults,
        'scan_rotation_results' => $rotationResults, 'explicit_scan_then_revoke' => $scanFirst[0]['result']['status'] ?? null,
        'explicit_scan_then_replace' => $replaceAfterAdmission[0]['http_status'] ?? null,
        'concurrent_replacements' => $replacementResults, 'revocation_committed' => true,
        'attendance_before_or_absent_after_revoke' => true, 'ticket_replacement_serialized' => true], JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    $db->table('community_services.event_ticket_security_audit')->where('ticket_id', $ticketId)->delete();
    $db->table('community_services.event_attendance_audit')->where('ticket_id', $ticketId)->delete();
    $db->table('community_services.event_attendance')->where('ticket_id', $ticketId)->delete();
    $db->table('community_services.event_tickets')->where('id', $ticketId)->delete();
    $db->table('community_services.event_order_lines')->where('id', $lineId)->delete();
    $db->table('community_services.event_orders')->where('id', $orderId)->delete();
    $db->table('community_services.event_inventory_holds')->where('id', $holdId)->delete();
    $db->table('community_services.event_authority_assignments')->whereIn('id', [$eventGrantId, $gateGrantId])->delete();
    $db->table('community_services.event_gates')->where('id', $gateId)->delete();
    $db->table('community_services.event_drafts')->where('id', $eventId)->delete();
}
