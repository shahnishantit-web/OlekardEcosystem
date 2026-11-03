<?php

declare(strict_types=1);

use App\Modules\Events\Application\FreeEventRegistrationService;
use App\Modules\Events\Domain\Contracts\ComplimentaryAllocationApprovalContract;
use App\Modules\Events\Domain\Permissions\EventPermissions;
use App\Modules\Events\Domain\ValueObjects\ComplimentaryAllocationApprovalEvidence;
use App\Modules\Identity\Domain\Contracts\IdentityModuleContract;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

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
$identity->shouldReceive('hasPermission')->withArgs(static fn (string $actorId, string $permission): bool => $permission === EventPermissions::UPDATE)->andReturn(true);
$app->instance(IdentityModuleContract::class, $identity);
$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$db = DB::connection('community_services');
$expectedDatabase = (string) env('P26_DISPOSABLE_DATABASE', '');
$assert($expectedDatabase !== '', 'Set P26_DISPOSABLE_DATABASE to the disposable test database name.');
$assert($db->getDriverName() === 'pgsql', 'P26 concurrency verification accepts PostgreSQL only.');
$assert($db->getDatabaseName() === $expectedDatabase, 'Refusing to write outside P26_DISPOSABLE_DATABASE.');
$platform = DB::connection('platform');
$barrierRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'p26-free-'.bin2hex(random_bytes(8));
if (! mkdir($barrierRoot, 0700)) {
    throw new RuntimeException('Could not create local worker barrier.');
}
$events = [];
$createEvent = static function (int $capacity) use ($db, &$events): array {
    $eventId = (string) Uuid::uuid7();
    $categoryId = (string) Uuid::uuid7();
    $organisationId = (string) Uuid::uuid7();
    $actorId = (string) Uuid::uuid7();
    $now = now();
    $db->table('community_services.event_drafts')->insert([
        'id' => $eventId, 'organisation_id' => $organisationId, 'created_by' => $actorId,
        'title' => 'P26 PostgreSQL free allocation fixture', 'organiser_name' => 'Test only',
        'sponsors' => json_encode([], JSON_THROW_ON_ERROR), 'capacity' => $capacity, 'currency_code' => 'BWP',
        'timezone' => 'Africa/Gaborone', 'starts_at' => $now->copy()->addDays(2),
        'ends_at' => $now->copy()->addDays(2)->addHours(2), 'sales_start_at' => $now->copy()->subHour(),
        'sales_end_at' => $now->copy()->addDay(), 'cancellation_policy' => 'test-only',
        'transfer_policy' => 'test-only', 'entry_policy' => 'test-only', 'version' => 1,
        'max_tickets_per_order' => $capacity, 'hold_duration_seconds' => null,
        'workflow_state' => 'published', 'sales_state' => 'active', 'published_version' => 1,
        'currency_precision_snapshot' => 2, 'currency_definition_updated_at' => $now,
        'currency_assignment_id' => null, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $db->table('community_services.event_draft_categories')->insert([
        'id' => $categoryId, 'event_id' => $eventId, 'name' => 'Free admission', 'capacity' => $capacity,
        'price_minor' => 0, 'allocation_type' => 'free', 'complimentary_quota' => null, 'issued_quantity' => 0,
        'max_per_person' => $capacity,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $events[] = $eventId;

    return ['event' => $eventId, 'category' => $categoryId, 'organisation' => $organisationId];
};
$createInvitation = static function (array $fixture, int $maxUses, string $recipient) use ($db): array {
    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $event = $db->table('community_services.event_drafts')->where('id', $fixture['event'])->first();
    $now = now();
    $db->table('community_services.event_invitations')->insert([
        'id' => (string) Uuid::uuid7(), 'event_id' => $fixture['event'], 'organisation_id' => $fixture['organisation'],
        'category_id' => $fixture['category'], 'token_hash' => hash('sha256', $token),
        'token_encrypted' => Crypt::encryptString($token), 'idempotency_key' => (string) Uuid::uuid7(),
        'request_hash' => hash('sha256', $token), 'recipient_actor_id' => $recipient,
        'valid_from' => $now->copy()->subMinute(), 'expires_at' => $event->starts_at,
        'max_uses' => $maxUses, 'uses_count' => 0, 'status' => 'active', 'created_by_actor_id' => (string) Uuid::uuid7(),
        'revoked_by_actor_id' => null, 'revoked_at' => null, 'created_at' => $now, 'updated_at' => $now,
    ]);

    return ['token' => $token, 'id' => (string) $db->table('community_services.event_invitations')
        ->where('token_hash', hash('sha256', $token))->value('id')];
};
$grantEventUpdate = static function (array $fixture, string $actorId) use ($db): void {
    $db->table('community_services.event_authority_assignments')->insert([
        'id' => (string) Uuid::uuid7(), 'scope_type' => 'event', 'scope_id' => $fixture['event'],
        'organisation_id' => $fixture['organisation'], 'event_id' => $fixture['event'], 'gate_id' => null,
        'actor_id' => $actorId, 'permission_slug' => EventPermissions::UPDATE,
        'granted_by_actor_id' => (string) Uuid::uuid7(), 'granted_at' => now(), 'revoked_by_actor_id' => null,
        'revoked_at' => null, 'created_at' => now(), 'updated_at' => now(),
    ]);
};
$race = static function (array $fixture, array $requests, string $label) use ($barrierRoot): array {
    $barrier = $barrierRoot.DIRECTORY_SEPARATOR.$label;
    $workers = [];
    foreach ($requests as $request) {
        $id = (string) Uuid::uuid7();
        $command = [PHP_BINARY, __DIR__.'/P26FreeRegistrationWorker.php', $fixture['event'], $fixture['category'],
            $request['actor'], $request['key'] ?? '-', $request['token'] ?? '-', $barrier, $id,
            $request['operation'] ?? 'register', $request['invitation_id'] ?? '-'];
        $pipes = [];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
        if (! is_resource($process)) {
            throw new RuntimeException('Could not start a separate-session PostgreSQL worker.');
        }
        fclose($pipes[0]);
        $workers[] = ['id' => $id, 'process' => $process, 'pipes' => $pipes];
    }
    $deadline = microtime(true) + 30;
    foreach ($workers as $worker) {
        while (! is_file($barrier.'.'.$worker['id'].'.ready')) {
            if (microtime(true) > $deadline) {
                file_put_contents($barrier.'.go', 'go', LOCK_EX);
                throw new RuntimeException('A worker failed to reach the concurrency barrier.');
            }
            usleep(10000);
        }
    }
    $pids = array_map(static fn (array $worker): string => trim((string) file_get_contents($barrier.'.'.$worker['id'].'.ready')), $workers);
    if (count(array_unique($pids)) !== count($workers)) {
        throw new RuntimeException('Workers did not use distinct PostgreSQL sessions.');
    }
    file_put_contents($barrier.'.go', 'go', LOCK_EX);
    $results = [];
    foreach ($workers as $worker) {
        $stdout = stream_get_contents($worker['pipes'][1]);
        $stderr = stream_get_contents($worker['pipes'][2]);
        fclose($worker['pipes'][1]);
        fclose($worker['pipes'][2]);
        $exit = proc_close($worker['process']);
        if ($exit !== 0) {
            throw new RuntimeException('P26 worker failed: '.$stderr.$stdout);
        }
        $results[] = json_decode((string) $stdout, true, 512, JSON_THROW_ON_ERROR);
    }

    return $results;
};

$summary = [];
try {
    $capacity = $createEvent(1);
    $lastCapacity = $race($capacity, [
        ['actor' => (string) Uuid::uuid7(), 'key' => 'last-a'],
        ['actor' => (string) Uuid::uuid7(), 'key' => 'last-b'],
    ], 'last-capacity');
    $assert(count(array_filter($lastCapacity, static fn (array $row): bool => $row['outcome'] === 'registered')) === 1,
        'Exactly one registration may consume the last capacity unit.');
    $assert((int) $db->table('community_services.event_draft_categories')->where('id', $capacity['category'])->value('issued_quantity') === 1,
        'The capacity count must equal one issued entitlement.');
    $assert($db->table('community_services.event_tickets')->where('event_id', $capacity['event'])->count() === 1,
        'Capacity race must not issue duplicate or excess tickets.');
    $summary['last_capacity_race'] = $lastCapacity;

    $inviteFixture = $createEvent(2);
    $recipient = (string) Uuid::uuid7();
    $invite = $createInvitation($inviteFixture, 1, $recipient);
    $token = $invite['token'];
    $inviteRace = $race($inviteFixture, [
        ['actor' => $recipient, 'key' => 'invite-a', 'token' => $token],
        ['actor' => $recipient, 'key' => 'invite-b', 'token' => $token],
    ], 'invitation-limit');
    $assert(count(array_filter($inviteRace, static fn (array $row): bool => $row['outcome'] === 'registered')) === 1,
        'An invitation with one use may produce exactly one registration under race.');
    $uses = (int) $db->table('community_services.event_invitations')->where('token_hash', hash('sha256', $token))->value('uses_count');
    $assert($uses === 1, 'Invitation use count must commit once.');
    $summary['invitation_use_race'] = ['workers' => $inviteRace, 'uses' => $uses];

    $repeatFixture = $createEvent(2);
    $actor = (string) Uuid::uuid7();
    $replays = $race($repeatFixture, [
        ['actor' => $actor, 'key' => 'same-request'], ['actor' => $actor, 'key' => 'same-request'],
    ], 'same-request');
    $assert(count(array_unique(array_column($replays, 'registration_id'))) === 1,
        'Repeated concurrent registration must return the same durable registration.');
    $assert($db->table('community_services.event_tickets')->where('event_id', $repeatFixture['event'])->count() === 1,
        'Idempotent registration replay must issue exactly one ticket.');
    $summary['same_request_race'] = $replays;

    // Explicit serial ordering: revocation commits before registration checks the invitation.
    $revokeFirst = $createEvent(2);
    $revokeFirstRecipient = (string) Uuid::uuid7();
    $revokeFirstOperator = (string) Uuid::uuid7();
    $grantEventUpdate($revokeFirst, $revokeFirstOperator);
    $revokeFirstInvite = $createInvitation($revokeFirst, 1, $revokeFirstRecipient);
    app(FreeEventRegistrationService::class)->revokeInvitation($revokeFirstInvite['id'], $revokeFirstOperator, (string) Uuid::uuid7());
    $deniedAfterRevoke = null;
    try {
        app(FreeEventRegistrationService::class)->register($revokeFirst['event'], $revokeFirst['category'],
            $revokeFirstRecipient, $revokeFirstInvite['token'], 'after-revocation', (string) Uuid::uuid7());
    } catch (Throwable $exception) {
        $deniedAfterRevoke = $exception;
    }
    $assert($deniedAfterRevoke !== null
        && $db->table('community_services.event_free_registrations')->where('event_id', $revokeFirst['event'])->count() === 0
        && (int) $db->table('community_services.event_invitations')->where('id', $revokeFirstInvite['id'])->value('uses_count') === 0
        && (int) $db->table('community_services.event_draft_categories')->where('id', $revokeFirst['category'])->value('issued_quantity') === 0,
        'A revocation committed before redemption eligibility is checked must prevent registration and use consumption.');
    $revokeFirstAudit = $db->table('community_services.event_invitation_audit')->where('invitation_id', $revokeFirstInvite['id'])
        ->orderBy('created_at')->pluck('action')->all();
    $assert($revokeFirstAudit === ['revoked'], 'Revocation-first audit must record revocation and no redemption.');
    $summary['revocation_before_redemption'] = ['denied' => true, 'registrations' => 0, 'uses' => 0, 'audit_actions' => $revokeFirstAudit];

    // Explicit serial ordering: redemption commits before revocation; historical same-key replay remains a replay.
    $redeemFirst = $createEvent(2);
    $redeemFirstRecipient = (string) Uuid::uuid7();
    $redeemFirstOperator = (string) Uuid::uuid7();
    $grantEventUpdate($redeemFirst, $redeemFirstOperator);
    $redeemFirstInvite = $createInvitation($redeemFirst, 1, $redeemFirstRecipient);
    $firstRegistration = app(FreeEventRegistrationService::class)->register($redeemFirst['event'], $redeemFirst['category'],
        $redeemFirstRecipient, $redeemFirstInvite['token'], 'redeem-first-key', (string) Uuid::uuid7());
    app(FreeEventRegistrationService::class)->revokeInvitation($redeemFirstInvite['id'], $redeemFirstOperator, (string) Uuid::uuid7());
    $historicalReplay = app(FreeEventRegistrationService::class)->register($redeemFirst['event'], $redeemFirst['category'],
        $redeemFirstRecipient, $redeemFirstInvite['token'], 'redeem-first-key', (string) Uuid::uuid7());
    $assert($firstRegistration['registration_id'] === $historicalReplay['registration_id']
        && $firstRegistration['ticket_id'] === $historicalReplay['ticket_id']
        && (int) $db->table('community_services.event_invitations')->where('id', $redeemFirstInvite['id'])->value('uses_count') === 1
        && $db->table('community_services.event_free_registrations')->where('event_id', $redeemFirst['event'])->count() === 1
        && $db->table('community_services.event_tickets')->where('event_id', $redeemFirst['event'])->count() === 1
        && $db->table('community_services.event_tickets')->where('id', $firstRegistration['ticket_id'])->value('status') === 'valid',
        'Same-key replay after later revocation must recover the committed registration without a second use or ticket.');
    $redeemFirstAudit = $db->table('community_services.event_invitation_audit')->where('invitation_id', $redeemFirstInvite['id'])
        ->orderBy('created_at')->pluck('action')->all();
    $assert($redeemFirstAudit === ['redeemed', 'revoked'], 'Redemption-first audit must retain redemption before later revocation.');
    $summary['redemption_before_revocation_and_replay'] = ['registration_id' => $firstRegistration['registration_id'],
        'ticket_id' => $firstRegistration['ticket_id'], 'replayed_same_entitlement' => true, 'ticket_status' => 'valid'];

    // Race a redemption and revocation after both independent PostgreSQL sessions reach a barrier.
    $raceFixture = $createEvent(2);
    $raceRecipient = (string) Uuid::uuid7();
    $raceInvite = $createInvitation($raceFixture, 1, $raceRecipient);
    $assignmentId = (string) Uuid::uuid7();
    $grantor = (string) Uuid::uuid7();
    $db->table('community_services.event_authority_assignments')->insert([
        'id' => $assignmentId, 'scope_type' => 'event', 'scope_id' => $raceFixture['event'],
        'organisation_id' => $raceFixture['organisation'], 'event_id' => $raceFixture['event'], 'gate_id' => null,
        'actor_id' => $grantor, 'permission_slug' => EventPermissions::UPDATE, 'granted_by_actor_id' => (string) Uuid::uuid7(),
        'granted_at' => now(), 'revoked_by_actor_id' => null, 'revoked_at' => null, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $raceResults = $race($raceFixture, [
        ['actor' => $raceRecipient, 'key' => 'race-redemption', 'token' => $raceInvite['token']],
        ['operation' => 'revoke', 'actor' => $grantor, 'invitation_id' => $raceInvite['id']],
    ], 'revoke-redemption-race');
    $raceRegistrationCount = (int) $db->table('community_services.event_free_registrations')->where('event_id', $raceFixture['event'])->count();
    $raceTicketCount = (int) $db->table('community_services.event_tickets')->where('event_id', $raceFixture['event'])->count();
    $raceUses = (int) $db->table('community_services.event_invitations')->where('id', $raceInvite['id'])->value('uses_count');
    $raceStatus = (string) $db->table('community_services.event_invitations')->where('id', $raceInvite['id'])->value('status');
    $assert(($raceRegistrationCount === 0 && $raceTicketCount === 0 && $raceUses === 0 && $raceStatus === 'revoked')
        || ($raceRegistrationCount === 1 && $raceTicketCount === 1 && $raceUses === 1 && $raceStatus === 'revoked'),
        'Concurrent revocation/redemption must serialize to one documented order with internally consistent counters and entitlement.');
    if ($raceRegistrationCount === 1) {
        $assert($db->table('community_services.event_tickets')->where('event_id', $raceFixture['event'])->value('status') === 'valid',
            'A committed registration is not retroactively invalidated by later invitation revocation.');
    }
    $auditActions = $db->table('community_services.event_invitation_audit')->where('event_id', $raceFixture['event'])
        ->orderBy('created_at')->pluck('action')->all();
    $assert(in_array('revoked', $auditActions, true)
        && (($raceRegistrationCount === 0 && ! in_array('redeemed', $auditActions, true))
            || ($raceRegistrationCount === 1 && in_array('redeemed', $auditActions, true))),
        'Invitation audit must match the committed serialization outcome.');
    $summary['concurrent_revoke_redemption'] = ['workers' => $raceResults, 'registration_count' => $raceRegistrationCount,
        'ticket_count' => $raceTicketCount, 'uses' => $raceUses, 'status' => $raceStatus, 'audit_actions' => $auditActions];

    $rollback = $createEvent(1);
    $rollbackActor = (string) Uuid::uuid7();
    $rollbackInvite = $createInvitation($rollback, 1, $rollbackActor);
    $suffix = bin2hex(random_bytes(5));
    $function = 'community_services.p26_fail_audit_'.$suffix;
    $trigger = 'p26_fail_audit_'.$suffix;
    $db->unprepared("CREATE FUNCTION {$function}() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF NEW.event_id = '{$rollback['event']}'::uuid AND NEW.actor_id = '{$rollbackActor}'::uuid THEN RAISE EXCEPTION 'P26 injected audit failure'; END IF; RETURN NEW; END; \$\$");
    $db->unprepared("CREATE TRIGGER {$trigger} BEFORE INSERT ON community_services.event_draft_audit FOR EACH ROW EXECUTE FUNCTION {$function}()");
    $rolledBack = false;
    try {
        app(FreeEventRegistrationService::class)->register($rollback['event'], $rollback['category'], $rollbackActor,
            $rollbackInvite['token'], 'rollback', (string) Uuid::uuid7());
    } catch (Throwable) {
        $rolledBack = true;
    } finally {
        $db->unprepared("DROP TRIGGER IF EXISTS {$trigger} ON community_services.event_draft_audit");
        $db->unprepared("DROP FUNCTION IF EXISTS {$function}()");
    }
    $assert($rolledBack && $db->table('community_services.event_free_registrations')->where('event_id', $rollback['event'])->count() === 0
        && $db->table('community_services.event_tickets')->where('event_id', $rollback['event'])->count() === 0
        && (int) $db->table('community_services.event_draft_categories')->where('id', $rollback['category'])->value('issued_quantity') === 0
        && (int) $db->table('community_services.event_invitations')->where('id', $rollbackInvite['id'])->value('uses_count') === 0
        && $db->table('community_services.event_invitation_audit')->where('invitation_id', $rollbackInvite['id'])->count() === 0,
        'Registration, invitation use, entitlement and issued capacity must rollback together.');
    $recovered = app(FreeEventRegistrationService::class)->register($rollback['event'], $rollback['category'], $rollbackActor,
        $rollbackInvite['token'], 'after-rollback', (string) Uuid::uuid7());
    $assert(is_string($recovered['ticket_id']), 'Capacity must be recoverable after transaction rollback.');
    $assert((int) $db->table('community_services.event_invitations')->where('id', $rollbackInvite['id'])->value('uses_count') === 1
        && $db->table('community_services.event_invitation_audit')->where('invitation_id', $rollbackInvite['id'])
            ->where('action', 'redeemed')->count() === 1,
        'Successful retry must commit a single invitation use and redemption audit after rollback.');
    $summary['rollback_recovery'] = ['rolled_back' => $rolledBack, 'recovered_ticket' => $recovered['ticket_id'],
        'invitation_uses' => 1, 'redemption_audit_count' => 1];
    echo json_encode(['status' => 'passed', 'database' => $expectedDatabase, 'scenarios' => $summary], JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    foreach ($events as $eventId) {
        $assignmentIds = $db->table('community_services.event_authority_assignments')->where('event_id', $eventId)->pluck('id');
        if ($assignmentIds->isNotEmpty()) {
            $db->table('community_services.event_authority_assignment_audit')->whereIn('assignment_id', $assignmentIds)->delete();
        }
        $db->table('community_services.event_authority_assignments')->where('event_id', $eventId)->delete();
        $ids = $db->table('community_services.event_tickets')->where('event_id', $eventId)->pluck('id');
        if ($ids->isNotEmpty()) {
            $db->table('community_services.event_attendance_audit')->whereIn('ticket_id', $ids)->delete();
            $db->table('community_services.event_attendance')->whereIn('ticket_id', $ids)->delete();
            $db->table('community_services.event_ticket_security_audit')->whereIn('ticket_id', $ids)->delete();
        }
        $db->table('community_services.event_tickets')->where('event_id', $eventId)->delete();
        $db->table('community_services.event_free_registrations')->where('event_id', $eventId)->delete();
        $db->table('community_services.event_invitation_audit')->where('event_id', $eventId)->delete();
        $db->table('community_services.event_invitations')->where('event_id', $eventId)->delete();
        $db->table('community_services.event_inventory_holds')->where('event_id', $eventId)->delete();
        $db->table('community_services.event_draft_audit')->where('event_id', $eventId)->delete();
        $db->table('community_services.event_draft_categories')->where('event_id', $eventId)->delete();
        $db->table('community_services.event_drafts')->where('id', $eventId)->delete();
    }
    foreach (glob($barrierRoot.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($barrierRoot);
}
