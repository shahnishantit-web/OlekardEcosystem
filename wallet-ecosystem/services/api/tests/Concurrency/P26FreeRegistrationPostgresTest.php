<?php

declare(strict_types=1);

/**
 * @phpstan-type CancellationFixture array{event: string, category: string, organisation: string, requester: string, reviewer: string, customer: string, cancellation_id: string, review_key: string, gate: string, operator: string, invitation: array{id: string, token: string}|null, ticket: string|null, credential: string|null}
 */

use App\Modules\Events\Application\AdmissionCredentialVault;
use App\Modules\Events\Application\EventAdmissionService;
use App\Modules\Events\Application\EventCancellationService;
use App\Modules\Events\Application\FreeEventRegistrationService;
use App\Modules\Events\Domain\Contracts\ComplimentaryAllocationApprovalContract;
use App\Modules\Events\Domain\Contracts\InvitationRecipientVerifier;
use App\Modules\Events\Domain\Permissions\EventPermissions;
use App\Modules\Events\Domain\ValueObjects\ComplimentaryAllocationApprovalEvidence;
use App\Modules\Identity\Domain\Contracts\ActorStepUpVerifierContract;
use App\Modules\Identity\Domain\Contracts\IdentityModuleContract;
use App\Modules\Payment\Domain\Contracts\PaymentRefundAdmissionEligibilityContract;
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
$identity->shouldReceive('hasPermission')->andReturn(true);
$app->instance(IdentityModuleContract::class, $identity);
$app->instance(InvitationRecipientVerifier::class, new class implements InvitationRecipientVerifier
{
    public function assertActiveCustomerActor(string $actorId): void {}
});
$stepUp = Mockery::mock(ActorStepUpVerifierContract::class);
$stepUp->shouldReceive('verifyStepUpForActor')->andReturn(true);
$app->instance(ActorStepUpVerifierContract::class, $stepUp);
$refundEligibility = Mockery::mock(PaymentRefundAdmissionEligibilityContract::class);
$refundEligibility->shouldReceive('hasAdmissionBlockingRefund')->andReturn(false);
$app->instance(PaymentRefundAdmissionEligibilityContract::class, $refundEligibility);
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
/** @return array{id: string, token: string} */
$createInvitation = static function (array $fixture, int $maxUses, string $recipient) use ($db): array {
    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $event = $db->table('community_services.event_drafts')->where('id', $fixture['event'])->first();
    if ($event === null) {
        throw new RuntimeException('Invitation fixture event is missing.');
    }
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
            $request['operation'] ?? 'register', $request['invitation_id'] ?? '-',
            $request['cancellation_id'] ?? '-', $request['gate_id'] ?? '-', $request['credential'] ?? '-'];
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
$grantPermission = static function (array $fixture, string $actorId, string $permission, ?string $gateId = null) use ($db): void {
    $scopeType = $gateId === null ? 'event' : 'gate';
    $scopeId = $gateId ?? $fixture['event'];
    $db->table('community_services.event_authority_assignments')->insert([
        'id' => (string) Uuid::uuid7(), 'scope_type' => $scopeType, 'scope_id' => $scopeId,
        'organisation_id' => $fixture['organisation'], 'event_id' => $fixture['event'], 'gate_id' => $gateId,
        'actor_id' => $actorId, 'permission_slug' => $permission,
        'granted_by_actor_id' => (string) Uuid::uuid7(), 'granted_at' => now(), 'revoked_by_actor_id' => null,
        'revoked_at' => null, 'created_at' => now(), 'updated_at' => now(),
    ]);
};
/** @return CancellationFixture */
$createCancellationScenario = static function (bool $withTicket) use ($createEvent, $createInvitation, $db, $grantPermission): array {
    $fixture = $createEvent(4);
    $requester = (string) Uuid::uuid7();
    $reviewer = (string) Uuid::uuid7();
    $operator = (string) Uuid::uuid7();
    $customer = (string) Uuid::uuid7();
    $grantPermission($fixture, $requester, EventPermissions::CANCEL_REQUEST);
    $grantPermission($fixture, $reviewer, EventPermissions::CANCEL_APPROVE);
    $request = app(EventCancellationService::class)->request(
        $fixture['event'], $requester, 1, 'P26 cancellation race fixture', 'request-'.Uuid::uuid7(), (string) Uuid::uuid7(),
    );
    $fixture['requester'] = $requester;
    $fixture['reviewer'] = $reviewer;
    $fixture['customer'] = $customer;
    $fixture['cancellation_id'] = (string) $request['id'];
    $fixture['review_key'] = 'review-'.Uuid::uuid7();
    $fixture['gate'] = (string) Uuid::uuid7();
    $fixture['operator'] = $operator;
    $fixture['invitation'] = null;
    $fixture['ticket'] = null;
    $fixture['credential'] = null;
    $db->table('community_services.event_gates')->insert([
        'id' => $fixture['gate'], 'organisation_id' => $fixture['organisation'], 'event_id' => $fixture['event'],
        'name' => 'P26 race gate '.$fixture['gate'], 'status' => 'active', 'created_by_actor_id' => $reviewer,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $grantPermission($fixture, $operator, EventPermissions::GATE_VALIDATE, $fixture['gate']);
    if (! $withTicket) {
        $fixture['invitation'] = $createInvitation($fixture, 1, $customer);
    }
    if ($withTicket) {
        $registrationId = (string) Uuid::uuid7();
        $ticketId = (string) Uuid::uuid7();
        $credential = app(AdmissionCredentialVault::class)->create();
        $now = now();
        $db->table('community_services.event_free_registrations')->insert([
            'id' => $registrationId, 'event_id' => $fixture['event'], 'organisation_id' => $fixture['organisation'],
            'category_id' => $fixture['category'], 'invitation_id' => null, 'attendee_actor_id' => $customer,
            'entitlement_source' => 'free', 'idempotency_key' => 'race-fixture-'.$registrationId,
            'request_hash' => hash('sha256', $registrationId), 'status' => 'registered', 'registered_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $db->table('community_services.event_tickets')->insert([
            'id' => $ticketId, 'order_id' => null, 'order_line_id' => null, 'free_registration_id' => $registrationId,
            'entitlement_source' => 'free', 'entitlement_number' => 1, 'event_id' => $fixture['event'],
            'organisation_id' => $fixture['organisation'], 'owner_actor_id' => $customer, 'category_id' => $fixture['category'],
            'category_name' => 'Free admission', 'credential_hash' => $credential['hash'],
            'credential_encrypted' => $credential['encrypted'], 'status' => 'valid', 'issued_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $db->table('community_services.event_draft_categories')->where('id', $fixture['category'])->increment('issued_quantity');
        $fixture['ticket'] = $ticketId;
        $fixture['credential'] = $credential['plaintext'];
    }

    return $fixture;
};
/** @param CancellationFixture $fixture @param array<string, string> $loser @return array<string, mixed> */
$blockedLoser = static function (array $fixture, array $loser, string $label, callable $winner) use ($db, $barrierRoot): array {
    $barrier = $barrierRoot.DIRECTORY_SEPARATOR.$label;
    $workerId = (string) Uuid::uuid7();
    $command = [PHP_BINARY, __DIR__.'/P26FreeRegistrationWorker.php', $fixture['event'], $fixture['category'],
        $loser['actor'], $loser['key'] ?? '-', $loser['token'] ?? '-', $barrier, $workerId, $loser['operation'], '-',
        $fixture['cancellation_id'], $fixture['gate'], $fixture['credential'] ?? '-'];
    $db->beginTransaction();
    $winner();
    $pipes = [];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
    if (! is_resource($process)) {
        $db->rollBack();
        throw new RuntimeException('Could not start a cancellation-race PostgreSQL worker.');
    }
    fclose($pipes[0]);
    $deadline = microtime(true) + 20;
    while (! is_file($barrier.'.'.$workerId.'.ready')) {
        if (microtime(true) > $deadline) {
            file_put_contents($barrier.'.go', 'go', LOCK_EX);
            proc_terminate($process);
            $db->rollBack();
            throw new RuntimeException('Race worker did not reach its session barrier.');
        }
        usleep(10000);
    }
    $pid = (int) file_get_contents($barrier.'.'.$workerId.'.ready');
    try {
        file_put_contents($barrier.'.go', 'go', LOCK_EX);
        $lockDeadline = microtime(true) + 20;
        do {
            $state = $db->selectOne('SELECT wait_event_type, state FROM pg_stat_activity WHERE pid = ?', [$pid]);
            if ($state?->wait_event_type === 'Lock') {
                break;
            }
            if (microtime(true) > $lockDeadline) {
                throw new RuntimeException('Competing worker did not enter a PostgreSQL lock wait.');
            }
            usleep(10000);
        } while (true);
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->transactionLevel() > 0) {
            $db->rollBack();
        }
        file_put_contents($barrier.'.go', 'go', LOCK_EX);
        proc_terminate($process);
        throw $exception;
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) {
        throw new RuntimeException('Cancellation-race worker failed: '.$stderr.$stdout);
    }

    $result = json_decode((string) $stdout, true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($result)) {
        throw new RuntimeException('Race worker returned invalid JSON.');
    }

    return $result + ['overlap_wait_confirmed' => true, 'worker_pid' => $pid];
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

    $financialBeforeCancellationRaces = [
        'payment_intents' => DB::connection('payment')->table('payment.payment_intents')->count(),
        'payment_attempts' => DB::connection('payment')->table('payment.payment_attempts')->count(),
        'refunds' => DB::connection('payment')->table('payment.payment_refunds')->count(),
        'wallet_captures' => DB::connection('wallet')->table('wallet.wallet_capture_records')->count(),
        'ledger_journals' => DB::connection('ledger')->table('ledger.journals')->count(),
        'payouts' => DB::connection('payment')->table('payment.payout_settlement_records')->count(),
        'settlements' => DB::connection('reconciliation')->table('reconciliation.settlement_batches')->count(),
    ];
    $reviewCancellation = static function (array $fixture): array {
        return app(EventCancellationService::class)->review($fixture['event'], $fixture['cancellation_id'],
            $fixture['reviewer'], 1, true, null, $fixture['review_key'], (string) Uuid::uuid7());
    };

    // Cancellation commits first while registration is demonstrably waiting on its event-row lock.
    $cancelFirst = $createCancellationScenario(false);
    $cancelFirstInvitation = $cancelFirst['invitation'];
    if ($cancelFirstInvitation === null) {
        throw new RuntimeException('Expected the cancellation race invitation fixture.');
    }
    $cancelFirstLoser = $blockedLoser($cancelFirst, [
        'actor' => $cancelFirst['customer'], 'operation' => 'register', 'key' => 'cancel-first-register',
        'token' => $cancelFirstInvitation['token'],
    ], 'cancel-first-register', function () use ($reviewCancellation, $cancelFirst): void {
        $reviewCancellation($cancelFirst);
    });
    $assert($cancelFirstLoser['overlap_wait_confirmed'] && $cancelFirstLoser['outcome'] === 'denied',
        'Registration must be blocked while effective cancellation is uncommitted and denied after it commits.');
    $assert($db->table('community_services.event_cancellation_requests')->where('id', $cancelFirst['cancellation_id'])->value('status') === 'effective'
        && $db->table('community_services.event_free_registrations')->where('event_id', $cancelFirst['event'])->count() === 0
        && $db->table('community_services.event_tickets')->where('event_id', $cancelFirst['event'])->count() === 0
        && (int) $db->table('community_services.event_draft_categories')->where('id', $cancelFirst['category'])->value('issued_quantity') === 0
        && (int) $db->table('community_services.event_invitations')->where('id', $cancelFirstInvitation['id'])->value('uses_count') === 0
        && $db->table('community_services.event_draft_audit')->where('event_id', $cancelFirst['event'])->where('action', 'free_entitlement_issued')->count() === 0,
        'Cancellation-first must leave no registration, ticket, capacity usage or issuance success audit.');
    $reviewCancellation($cancelFirst);
    $cancelFirstOutbox = DB::connection('platform')->table('platform.outbox_events')->where('aggregate_id', $cancelFirst['event'])
        ->where('event_name', 'Events.Cancellation.Effective')->count();
    $assert($cancelFirstOutbox === 1, 'Cancellation review replay must not duplicate the effective cancellation outbox intent.');
    $summary['cancellation_before_registration'] = ['worker' => $cancelFirstLoser, 'registrations' => 0, 'tickets' => 0,
        'issued_quantity' => 0, 'success_audits' => 0, 'effective_outbox_events_after_replay' => $cancelFirstOutbox];

    // Registration commits first while cancellation review is demonstrably waiting on the event-row lock.
    $registrationFirst = $createCancellationScenario(false);
    $registrationInvitation = $registrationFirst['invitation'];
    if ($registrationInvitation === null) {
        throw new RuntimeException('Expected the registration race invitation fixture.');
    }
    $registrationResult = null;
    $registrationLoser = $blockedLoser($registrationFirst, [
        'actor' => $registrationFirst['reviewer'], 'operation' => 'cancel', 'key' => $registrationFirst['review_key'],
    ], 'registration-first-cancel', function () use ($registrationFirst, $registrationInvitation, &$registrationResult): void {
        $registrationResult = app(FreeEventRegistrationService::class)->register(
            $registrationFirst['event'], $registrationFirst['category'], $registrationFirst['customer'], $registrationInvitation['token'],
            'registration-first', (string) Uuid::uuid7(),
        );
    });
    $assert($registrationLoser['overlap_wait_confirmed'] && $registrationLoser['outcome'] === 'cancelled',
        'Cancellation review must proceed after the overlapping registration commits.');
    if (! is_array($registrationResult)) {
        throw new RuntimeException('Registration winner returned no result.');
    }
    $registrationTicket = $db->table('community_services.event_tickets')->where('id', $registrationResult['ticket_id'])->first();
    if ($registrationTicket === null) {
        throw new RuntimeException('Registration winner ticket was not persisted.');
    }
    $assert($db->table('community_services.event_free_registrations')->where('event_id', $registrationFirst['event'])->count() === 1
        && $db->table('community_services.event_tickets')->where('event_id', $registrationFirst['event'])->count() === 1
        && (int) $db->table('community_services.event_draft_categories')->where('id', $registrationFirst['category'])->value('issued_quantity') === 1
        && (int) $db->table('community_services.event_invitations')->where('id', $registrationInvitation['id'])->value('uses_count') === 1
        && $registrationTicket->status === 'revoked'
        && $db->table('community_services.event_draft_audit')->where('event_id', $registrationFirst['event'])->where('action', 'free_entitlement_issued')->count() === 1,
        'Registration-first history must remain while its entitlement is invalidated by the committed cancellation.');
    $reviewCancellation($registrationFirst);
    $registrationOutbox = DB::connection('platform')->table('platform.outbox_events')->where('aggregate_id', $registrationFirst['event'])
        ->where('event_name', 'Events.Cancellation.Effective')->count();
    $assert($registrationOutbox === 1, 'Cancellation replay after registration-first must retain one effective outbox intent.');
    $summary['registration_before_cancellation'] = ['worker' => $registrationLoser, 'registration_id' => $registrationResult['registration_id'],
        'ticket_status' => $registrationTicket->status, 'issued_quantity' => 1, 'effective_outbox_events_after_replay' => $registrationOutbox];

    // Cancellation commits first while online admission is demonstrably waiting on the ticket row.
    $cancelFirstAdmission = $createCancellationScenario(true);
    $admissionLoser = $blockedLoser($cancelFirstAdmission, [
        'actor' => $cancelFirstAdmission['operator'], 'operation' => 'admit', 'key' => '-',
    ], 'cancel-first-admission', function () use ($reviewCancellation, $cancelFirstAdmission): void {
        $reviewCancellation($cancelFirstAdmission);
    });
    $assert($admissionLoser['overlap_wait_confirmed'] && $admissionLoser['outcome'] === 'denied'
        && $db->table('community_services.event_attendance')->where('ticket_id', $cancelFirstAdmission['ticket'])->count() === 0
        && $db->table('community_services.event_attendance_audit')->where('ticket_id', $cancelFirstAdmission['ticket'])->count() === 0
        && $db->table('community_services.event_tickets')->where('id', $cancelFirstAdmission['ticket'])->value('status') === 'revoked',
        'Cancellation-first admission must be denied with no attendance or success audit.');
    $reviewCancellation($cancelFirstAdmission);
    $summary['cancellation_before_admission'] = ['worker' => $admissionLoser, 'attendance' => 0, 'admission_audits' => 0,
        'ticket_status' => 'revoked'];

    // Admission commits first while effective cancellation is demonstrably waiting on the same ticket row.
    $admissionFirst = $createCancellationScenario(true);
    $admissionCredential = $admissionFirst['credential'];
    if (! is_string($admissionCredential)) {
        throw new RuntimeException('Expected the admission race credential fixture.');
    }
    $admissionResult = null;
    $cancellationLoser = $blockedLoser($admissionFirst, [
        'actor' => $admissionFirst['reviewer'], 'operation' => 'cancel', 'key' => $admissionFirst['review_key'],
    ], 'admission-first-cancel', function () use ($admissionFirst, $admissionCredential, &$admissionResult): void {
        $admissionResult = app(EventAdmissionService::class)->validateOnline(
            $admissionFirst['event'], $admissionFirst['gate'], $admissionFirst['operator'], $admissionCredential, (string) Uuid::uuid7(),
        );
    });
    if (! is_array($admissionResult)) {
        throw new RuntimeException('Admission winner returned no result.');
    }
    $postCancellationAdmission = app(EventAdmissionService::class)->validateOnline(
        $admissionFirst['event'], $admissionFirst['gate'], $admissionFirst['operator'], $admissionCredential, (string) Uuid::uuid7(),
    );
    $assert($admissionResult['status'] === 'admitted' && $cancellationLoser['overlap_wait_confirmed']
        && $cancellationLoser['outcome'] === 'cancelled'
        && $postCancellationAdmission['status'] === 'denied'
        && $db->table('community_services.event_attendance')->where('ticket_id', $admissionFirst['ticket'])->count() === 1
        && $db->table('community_services.event_attendance_audit')->where('ticket_id', $admissionFirst['ticket'])->where('action', 'admitted')->count() === 1
        && $db->table('community_services.event_tickets')->where('id', $admissionFirst['ticket'])->value('status') === 'revoked',
        'Admission-first history and audit must remain while cancellation blocks later ticket use.');
    $reviewCancellation($admissionFirst);
    $summary['admission_before_cancellation'] = ['admission' => $admissionResult['status'], 'worker' => $cancellationLoser,
        'post_cancellation_admission' => $postCancellationAdmission['status'], 'attendance' => 1,
        'admission_audits' => 1, 'ticket_status' => 'revoked'];

    $financialAfterCancellationRaces = [
        'payment_intents' => DB::connection('payment')->table('payment.payment_intents')->count(),
        'payment_attempts' => DB::connection('payment')->table('payment.payment_attempts')->count(),
        'refunds' => DB::connection('payment')->table('payment.payment_refunds')->count(),
        'wallet_captures' => DB::connection('wallet')->table('wallet.wallet_capture_records')->count(),
        'ledger_journals' => DB::connection('ledger')->table('ledger.journals')->count(),
        'payouts' => DB::connection('payment')->table('payment.payout_settlement_records')->count(),
        'settlements' => DB::connection('reconciliation')->table('reconciliation.settlement_batches')->count(),
    ];
    $assert($financialBeforeCancellationRaces === $financialAfterCancellationRaces,
        'Free cancellation race paths must not create Payment, capture, refund, Ledger or payout records.');
    foreach ([$cancelFirst, $registrationFirst, $cancelFirstAdmission, $admissionFirst] as $fixture) {
        $effectiveCount = DB::connection('platform')->table('platform.outbox_events')->where('aggregate_id', $fixture['event'])
            ->where('event_name', 'Events.Cancellation.Effective')->count();
        $assert($effectiveCount === 1
            && $db->table('community_services.event_cancellation_requests')->where('id', $fixture['cancellation_id'])->value('status') === 'effective'
            && $db->table('community_services.event_draft_audit')->where('event_id', $fixture['event'])->where('action', 'cancellation_effective')->count() === 1
            && $db->table('community_services.event_orders')->where('event_id', $fixture['event'])->count() === 0
            && $db->table('community_services.event_cancellation_financial_cases')->where('event_id', $fixture['event'])->count() === 0,
            'Cancellation replay must retain one terminal audit/outbox intent and free cases must not create orders or financial recovery records.');
    }
    $summary['financial_absence'] = $financialAfterCancellationRaces;
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
        $db->table('community_services.event_cancellation_financial_cases')->where('event_id', $eventId)->delete();
        $db->table('community_services.event_cancellation_requests')->where('event_id', $eventId)->delete();
        DB::connection('platform')->table('platform.outbox_events')->where('aggregate_id', $eventId)
            ->where('event_name', 'Events.Cancellation.Effective')->delete();
        $db->table('community_services.event_gates')->where('event_id', $eventId)->delete();
        $db->table('community_services.event_draft_audit')->where('event_id', $eventId)->delete();
        $db->table('community_services.event_draft_categories')->where('event_id', $eventId)->delete();
        $db->table('community_services.event_drafts')->where('id', $eventId)->delete();
    }
    foreach (glob($barrierRoot.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($barrierRoot);
}
