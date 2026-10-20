<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Support;

use App\Modules\Fees\Domain\Contracts\FeesModuleContract;
use App\Modules\Identity\Domain\Contracts\ActorStepUpVerifierContract;
use App\Modules\Identity\Domain\Contracts\CredentialRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\JwksProviderContract;
use App\Modules\Identity\Domain\Services\TokenService;
use App\Modules\Notification\Application\Jobs\DeliverInAppNotificationJob;
use App\Modules\Notification\Application\Services\NotificationDeliveryService;
use App\Modules\Notification\Infrastructure\Models\NotificationDeliveryModel;
use App\Modules\Notification\Infrastructure\Models\NotificationModel;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Infrastructure\EventBus\InProcessDriver;
use App\Modules\Platform\Infrastructure\Persistence\OutboxEventModel;
use App\Modules\Support\Application\Services\AdminDisputeRefundService;
use App\Modules\Support\Application\Services\SupportTicketNoteService;
use App\Modules\Support\Infrastructure\Models\DisputeModel;
use App\Modules\Transaction\Infrastructure\Models\TransactionProjectionModel;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Ecdsa\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Ramsey\Uuid\Uuid;
use Tests\Feature\Modules\Support\Concerns\CreatesDisputeFixtures;
use Tests\Support\FakeRedisConnection;
use Tests\TestCase;

final class AdminSupportTest extends TestCase
{
    use CreatesDisputeFixtures, DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        app('cache')->forgetDriver('redis');
        config(['cache.default' => 'array']);
        $manager = app('redis');
        $p = new \ReflectionProperty($manager, 'connections');
        $c = $p->getValue($manager);
        $c['identity'] = new FakeRedisConnection;
        $p->setValue($manager, $c);
        Queue::fake();
        if (! Schema::connection('platform')->hasTable('support.admin_cases') && file_exists(app_path('Modules/Support/Infrastructure/Migrations/2026_10_15_021000_admin_support_cases.php'))) {
            (require app_path('Modules/Support/Infrastructure/Migrations/2026_10_15_021000_admin_support_cases.php'))->up();
        }
    }

    private function admin(array $permissions = ['support.investigate', 'support.assign', 'support.resolve', 'dispute.refund', 'dispute.escalate']): string
    {
        $actor = $this->createAuthenticatedAdmin($permissions);
        $this->actingAs($actor);

        return $actor->id;
    }

    public function test_queue_filters_and_detail_include_existing_notes(): void
    {
        $actor = $this->admin();
        $t = $this->createTicket($actor);
        $this->getJson('/v1/admin/support/cases?type=Support&status=Open&priority=medium')->assertOk()->assertJsonPath('data.items.0.id', $t->id);
        $this->getJson('/v1/admin/support/cases/'.$t->id)->assertOk()->assertJsonPath('data.description', $t->description);
    }

    public function test_internal_note_and_related_case_are_auditable(): void
    {
        $actor = $this->admin();
        $t = $this->createTicket($actor);
        $other = $this->createTicket($actor);
        $this->patchJson('/v1/admin/support/cases/'.$t->id, ['action' => 'note', 'content' => 'Internal investigation'])->assertOk();
        $this->patchJson('/v1/admin/support/cases/'.$t->id, ['action' => 'link', 'related_case_id' => $other->id])->assertOk();
        $this->getJson('/v1/admin/support/cases/'.$t->id)->assertOk()->assertJsonFragment(['content' => 'Internal investigation'])->assertJsonFragment(['related_case_id' => $other->id]);
    }

    public function test_financial_action_rejects_missing_stepup_and_permission(): void
    {
        $actor = $this->admin();
        $d = $this->createDispute((string) Uuid::uuid7(), $actor, 'approved');
        $this->postJson('/v1/admin/support/cases/'.$d->id.'/refund', ['amount_minor' => 100, 'reason' => 'Valid dispute'], ['Idempotency-Key' => (string) Uuid::uuid7()])->assertForbidden();
        $this->admin(['support.investigate']);
        $this->postJson('/v1/admin/support/cases/'.$d->id.'/refund', ['amount_minor' => 100, 'reason' => 'Valid dispute'])->assertForbidden();
        $this->assertSame('approved', $d->fresh()->status->value);
    }

    private function refundFixture(string $actor): array
    {
        $id = (string) Uuid::uuid7();
        $wallet = (string) Uuid::uuid7();
        TransactionProjectionModel::create(['id' => $id, 'reference' => 'TEST-'.bin2hex(random_bytes(8)), 'type' => 'transfer', 'status' => 'completed', 'amount' => 1000, 'currency_code' => 'BWP', 'direction' => 'debit', 'wallet_id' => $wallet, 'customer_id' => $actor, 'source_module' => 'transfer', 'completed_at' => now()]);
        $d = $this->createDispute($id, $actor, 'approved', null, 'BWP');
        $contract = $this->createStub(WalletModuleContract::class);
        $contract->method('ownerActorId')->willReturn($actor);
        $contract->method('getPocketLedgerAccountRef')->willReturn('wallet:'.$wallet.':BWP');
        $this->instance(WalletModuleContract::class, $contract);

        return [$d, $id];
    }

    private function proof(string $actor, string $id, string $session = 'support-test-session'): string
    {
        return app(TokenService::class)->issueStepUpToken($actor, $session, 'ADMIN', 'support.refund:'.$id, 'test-ip', (string) Uuid::uuid7())->stepUpAccessToken;
    }

    private function refundRequest(string $actor, DisputeModel $d, int $amount = 1000, ?string $key = null, bool $confirm = false): TestResponse
    {
        return $this->postJson('/v1/admin/support/cases/'.$d->id.'/refund', ['amount_minor' => $amount, 'reason' => 'Confirmed merchant dispute', 'step_up_token' => $this->proof($actor, $d->id), 'confirm' => $confirm], ['Idempotency-Key' => $key ?? (string) Uuid::uuid7(), 'X-Test-Session-Id' => 'support-test-session']);
    }

    public function test_refund_posts_real_balanced_ledger_and_linked_transaction_once(): void
    {
        $actor = $this->admin();
        [$d,$original] = $this->refundFixture($actor);
        $key = (string) Uuid::uuid7();
        $this->refundRequest($actor, $d, 600, $key)->assertOk()->assertJsonPath('data.raw_status', 'refunded');
        $this->refundRequest($actor, $d, 600, $key)->assertOk();
        $this->refundRequest($actor, $d, 700, $key)->assertConflict();
        $this->refundRequest($actor, $d, 600)->assertConflict();
        $refund = DB::connection('platform')->table('transaction.transaction_projections')->where('parent_transaction_id', $original)->sole();
        $this->assertSame(600, (int) $refund->amount);
        $this->assertSame('refund', $refund->type);
        $this->assertSame('completed', DB::connection('platform')->table('transaction.transaction_status_history')->where('transaction_id', $refund->id)->sole()->new_status);
        $lines = DB::connection('platform')->table('ledger.journal_lines')->where('journal_id', $refund->ledger_journal_id)->get();
        $this->assertCount(2, $lines);
        $this->assertSame(600, (int) $lines->where('direction', 'debit')->sum('amount'));
        $this->assertSame(600, (int) $lines->where('direction', 'credit')->sum('amount'));
        $this->assertSame(1, DB::connection('platform')->table('platform.outbox_events')->where('event_name', 'DISPUTE_RESOLVED_WITH_REFUND')->whereJsonContains('payload->case_id', $d->id)->count());
    }

    public function test_refund_bounds_and_wrong_session_or_forged_proof_are_rejected(): void
    {
        $actor = $this->admin();
        [$d] = $this->refundFixture($actor);
        $this->refundRequest($actor, $d, 1001)->assertUnprocessable();
        $token = $this->proof($actor, $d->id, 'wrong-session');
        $this->postJson('/v1/admin/support/cases/'.$d->id.'/refund', ['amount_minor' => 100, 'reason' => 'Test', 'step_up_token' => $token], ['Idempotency-Key' => (string) Uuid::uuid7(), 'X-Test-Session-Id' => 'support-test-session'])->assertForbidden();
        $parts = explode('.', $this->proof($actor, $d->id));
        $parts[2] = str_repeat('A', strlen($parts[2]));
        $this->postJson('/v1/admin/support/cases/'.$d->id.'/refund', ['amount_minor' => 100, 'reason' => 'Test', 'step_up_token' => implode('.', $parts)], ['Idempotency-Key' => (string) Uuid::uuid7(), 'X-Test-Session-Id' => 'support-test-session'])->assertForbidden();
        $this->assertSame('approved', $d->fresh()->status->value);
    }

    public function test_maker_checker_requires_independent_matching_confirmation(): void
    {
        config(['support.dispute.refund.require_second_approval' => true]);
        $actor = $this->admin();
        [$d,$original] = $this->refundFixture($actor);
        $this->refundRequest($actor, $d, 500)->assertOk()->assertJsonPath('data.refund_pending_confirmation', true);
        $this->refundRequest($actor, $d, 500, null, true)->assertConflict();
        $this->assertSame(0, DB::connection('platform')->table('transaction.transaction_projections')->where('parent_transaction_id', $original)->count());
        $second = $this->admin();
        $this->refundRequest($second, $d, 501, null, true)->assertConflict();
        $this->refundRequest($second, $d, 500, null, true)->assertOk()->assertJsonPath('data.raw_status', 'refunded');
    }

    public function test_fee_failure_rolls_back_real_journal_transaction_and_case(): void
    {
        $actor = $this->admin();
        [$d,$original] = $this->refundFixture($actor);
        $fees = $this->createMock(FeesModuleContract::class);
        $fees->method('reverseFee')->willThrowException(new \RuntimeException('simulated fee failure'));
        $this->instance(FeesModuleContract::class, $fees);
        $this->refundRequest($actor, $d)->assertStatus(500);
        $this->assertSame('approved', $d->fresh()->status->value);
        $this->assertSame(0, DB::connection('platform')->table('transaction.transaction_projections')->where('parent_transaction_id', $original)->count());
        $this->assertSame(0, DB::connection('platform')->table('ledger.journals')->where('transaction_reference', $original)->count());
    }

    public function test_escalation_starts_exact_24_hour_deadline_notifies_and_highlights_overdue(): void
    {
        $this->travelTo(now()->startOfSecond());
        $actor = $this->admin();
        $t = $this->createTicket($actor);
        $this->patchJson('/v1/admin/support/cases/'.$t->id, ['action' => 'escalate', 'assignee_id' => $actor, 'reason' => 'financial_review'])->assertOk()->assertJsonPath('data.status', 'Escalated');
        $meta = DB::connection('platform')->table('support.admin_cases')->where('case_id', $t->id)->sole();
        $this->assertSame(24 * 3600, (int) now()->diffInSeconds(Carbon::parse($meta->deadline)));
        $this->assertSame(1, DB::connection('platform')->table('platform.outbox_events')->where('event_name', 'SUPPORT_CASE_ESCALATED')->whereJsonContains('payload->case_id', $t->id)->count());
        $this->travel(25)->hours();
        $this->getJson('/v1/admin/support/cases?status=Escalated')->assertOk()->assertJsonPath('data.items.0.overdue', true);
        $this->patchJson('/v1/admin/support/cases/'.$t->id, ['action' => 'escalate', 'assignee_id' => $actor, 'reason' => 'financial_review'])->assertConflict();
        $this->travelBack();
    }

    public function test_private_evidence_mime_auth_and_download_scope(): void
    {
        Storage::fake('local');
        $actor = $this->admin();
        $t = $this->createTicket($actor);
        $other = $this->createTicket($actor);
        $this->postJson('/v1/admin/support/cases/'.$t->id.'/attachments', ['filename' => 'malicious.pdf', 'content_base64' => base64_encode('<?php echo "bad";')])->assertUnprocessable();
        $r = $this->postJson('/v1/admin/support/cases/'.$t->id.'/attachments', ['filename' => 'proof.pdf', 'content_base64' => base64_encode("%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF")])->assertOk();
        $id = $r->json('data.evidence.0.id');
        $this->assertStringNotContainsString('file_key', $r->getContent());
        $this->get('/v1/admin/support/cases/'.$t->id.'/attachments/'.$id)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/v1/admin/support/cases/'.$other->id.'/attachments/'.$id)->assertNotFound();
        $this->admin(['support.create', 'dispute.raise']);
        $this->getJson('/v1/admin/support/cases/'.$t->id)->assertForbidden();
    }

    public function test_outbox_driver_delivers_notifications_idempotently_to_real_e17(): void
    {
        $actor = $this->admin();
        $t = $this->createTicket($actor);
        $this->patchJson('/v1/admin/support/cases/'.$t->id, ['action' => 'escalate', 'assignee_id' => $actor, 'reason' => 'financial_review'])->assertOk();
        $event = OutboxEventModel::where('event_name', 'SUPPORT_CASE_ESCALATED')->where('payload->case_id', $t->id)->sole();
        $driver = app(InProcessDriver::class);
        $driver->dispatch($event);
        $driver->dispatch($event);
        $this->assertSame(1, NotificationModel::where('source_event_id', $event->payload['id'])->count());
    }

    public function test_legacy_refund_endpoints_share_controls_currency_and_response_contract(): void
    {
        $actor = $this->admin();
        [$d] = $this->refundFixture($actor);
        $headers = ['Idempotency-Key' => (string) Uuid::uuid7(), 'X-Test-Session-Id' => 'support-test-session'];
        $this->postJson('/v1/disputes/'.$d->id.'/refund', ['amount_minor' => 1000, 'currency' => 'BWP'], $headers)->assertForbidden();
        $payload = ['amount_minor' => 1000, 'currency' => 'USD', 'step_up_token' => $this->proof($actor, $d->id)];
        $this->postJson('/v1/disputes/'.$d->id.'/refund', $payload, $headers)->assertUnprocessable();
        $payload['currency'] = 'BWP';
        $this->postJson('/v1/disputes/'.$d->id.'/refund', $payload, $headers)->assertOk()->assertJsonPath('data.status', 'refunded');
        $this->postJson('/v1/disputes/'.$d->id.'/refund', $payload, $headers)->assertOk();
    }

    public function test_real_fee_reversal_and_customer_inapp_delivery(): void
    {
        $actor = $this->admin();
        [$d,$original] = $this->refundFixture($actor);
        app(FeesModuleContract::class)->recordFee($original, 25, null, 'BWP', 'wallet:test-fee:'.$d->id, 'revenue:fees:BWP', (string) Uuid::uuid7(), (string) Uuid::uuid7());
        $this->refundRequest($actor, $d)->assertOk();
        $fee = DB::connection('platform')->table('fees.fees')->where('transaction_id', $original)->sole();
        $this->assertSame('reversed', $fee->status);
        $this->assertNotNull($fee->reversal_ledger_entry_id);
        $event = OutboxEventModel::where('event_name', 'DISPUTE_RESOLVED_WITH_REFUND')->where('payload->case_id', $d->id)->sole();
        app(InProcessDriver::class)->dispatch($event);
        $notification = NotificationModel::where('source_event_id', $event->payload['id'])->sole();
        $this->assertSame($actor, $notification->recipient_id);
        $this->assertTrue($notification->mandatory);
        app(NotificationDeliveryService::class)->dispatchToChannels($notification);
        $delivery = NotificationDeliveryModel::where('notification_id', $notification->id)->where('channel', 'in_app')->sole();
        app()->call([new DeliverInAppNotificationJob($delivery->id), 'handle']);
        $this->assertSame('delivered', $delivery->fresh()->status->value);
    }

    public function test_different_connection_cannot_enter_refund_critical_section_and_remaining_cap(): void
    {
        $actor = $this->admin();
        [$d,$original] = $this->refundFixture($actor);
        $fees = $this->createMock(FeesModuleContract::class);
        $fees->method('reverseFee')->willReturnCallback(function () use ($original): void {
            $config = config('database.connections.platform');
            $pdo = new \PDO('pgsql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'], $config['username'], $config['password']);
            $stmt = $pdo->prepare('SELECT pg_try_advisory_xact_lock(hashtextextended(?, 0))');
            $stmt->execute(['support-refund:'.$original]);
            $this->assertFalse($stmt->fetchColumn());
        });
        $this->instance(FeesModuleContract::class, $fees);
        $this->refundRequest($actor, $d, 600)->assertOk();
        $new = $this->createDispute($original, $actor, 'approved', null, 'BWP');
        $this->refundRequest($actor, $new, 500)->assertUnprocessable();
        $this->assertSame('approved', $new->fresh()->status->value);
    }

    public function test_linked_dispute_is_not_duplicated_in_queue_and_date_pagination_validates(): void
    {
        $actor = $this->admin();
        $t = $this->createTicket($actor);
        $d = $this->createDispute((string) Uuid::uuid7(), $actor, 'raised', $t->id);
        $this->getJson('/v1/admin/support/cases')->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.id', $d->id);
        $this->getJson('/v1/admin/support/cases?date_from=not-a-date')->assertUnprocessable();
        $this->getJson('/v1/admin/support/cases?page=0')->assertUnprocessable();
        $this->getJson('/v1/admin/support/cases?status=Resolved')->assertOk()->assertJsonCount(0, 'data.items');
    }

    public function test_cross_actor_scope_and_expired_signed_stepup_rejected(): void
    {
        $actor = $this->admin();
        [$d] = $this->refundFixture($actor);
        $identity = app(ActorStepUpVerifierContract::class);
        $token = $this->proof($actor, $d->id);
        $this->assertFalse($identity->verifyStepUpForActor($token, 'support.refund:'.$d->id, (string) Uuid::uuid7(), 'support-test-session'));
        $this->assertFalse($identity->verifyStepUpForActor($token, 'support.refund:other', $actor, 'support-test-session'));
        $key = app(JwksProviderContract::class)->getSigningKey();
        $config = Configuration::forAsymmetricSigner(new Sha256, InMemory::plainText($key->privateKeyPem), InMemory::plainText($key->privateKeyPem));
        $now = new \DateTimeImmutable;
        $expired = $config->builder()->relatedTo($actor)->permittedFor('step-up:support.refund:'.$d->id)->issuedAt($now->modify('-1 hour'))->expiresAt($now->modify('-1 minute'))->withClaim('session_id', 'support-test-session')->withClaim('step_up_scope', 'support.refund:'.$d->id)->getToken($config->signer(), $config->signingKey())->toString();
        $this->assertFalse($identity->verifyStepUpForActor($expired, 'support.refund:'.$d->id, $actor, 'support-test-session'));
    }

    public function test_enrolled_pin_challenge_runs_real_identity_mfa_before_refund(): void
    {
        $actor = $this->admin();
        [$d] = $this->refundFixture($actor);
        app(CredentialRepositoryInterface::class)->create(['user_id' => $actor, 'credential_type' => 'pin', 'credential_hash' => Hash::make('821749'), 'status' => 'active']);
        $headers = ['X-Test-Session-Id' => 'support-test-session'];
        $challenge = $this->postJson('/v1/auth/step-up/initiate', ['scope' => 'support.refund:'.$d->id, 'operation_context' => ['risk_required' => true]], $headers)->assertOk()->assertJsonPath('data.step_up_required', true)->json('data.step_up_token');
        $this->postJson('/v1/auth/step-up/verify', ['step_up_token' => $challenge, 'method' => 'pin', 'credential' => 'wrong'], $headers)->assertUnauthorized();
        $proof = $this->postJson('/v1/auth/step-up/verify', ['step_up_token' => $challenge, 'method' => 'pin', 'credential' => '821749'], $headers)->assertOk()->json('data.step_up_access_token');
        $this->postJson('/v1/auth/step-up/verify', ['step_up_token' => $challenge, 'method' => 'pin', 'credential' => '821749'], $headers)->assertUnauthorized();
        $this->postJson('/v1/admin/support/cases/'.$d->id.'/refund', ['amount_minor' => 1000, 'reason' => 'Confirmed dispute', 'step_up_token' => $proof], $headers + ['Idempotency-Key' => (string) Uuid::uuid7()])->assertOk()->assertJsonPath('data.raw_status', 'refunded');
    }

    public function test_queue_pagination_to_only_date_and_legacy_escalation_deadline(): void
    {
        $actor = $this->admin();
        for ($i = 0; $i < 21; $i++) {
            $this->createTicket($actor);
        }
        $this->getJson('/v1/admin/support/cases?date_to='.now()->toDateString())->assertOk()->assertJsonCount(20, 'data.items')->assertJsonPath('data.last_page', 2);
        $this->getJson('/v1/admin/support/cases?page=2')->assertOk()->assertJsonCount(1, 'data.items');
        $d = $this->createDispute((string) Uuid::uuid7(), $actor, 'raised');
        $d->update(['escalated' => true, 'escalated_at' => now()->subHours(25), 'escalation_reason' => 'Existing escalation']);
        $this->getJson('/v1/admin/support/cases?type=Dispute&status=Escalated')->assertOk()->assertJsonPath('data.items.0.overdue', true);
        $this->getJson('/v1/admin/support/cases/'.$d->id)->assertOk()->assertJsonPath('data.overdue', true);
    }

    public function test_mixed_legacy_and_admin_notes_remain_chronological(): void
    {
        $actor = $this->admin();
        $t = $this->createTicket($actor);
        $old = app(SupportTicketNoteService::class)->addNote($t->id, $actor, 'admin', 'Earlier legacy note', true, (string) Uuid::uuid7());
        $old->created_at = now()->subHours(2);
        $old->save();
        $this->patchJson('/v1/admin/support/cases/'.$t->id, ['action' => 'note', 'content' => 'Later admin note'])->assertOk();
        $this->getJson('/v1/admin/support/cases/'.$t->id)->assertOk()->assertJsonPath('data.timeline.0.payload.content', 'Earlier legacy note');
    }

    public function test_unauthenticated_queue_is_denied(): void
    {
        $this->getJson('/v1/admin/support/cases')->assertUnauthorized();
    }

    public function test_evidence_decoded_size_and_count_limits_are_enforced(): void
    {
        Storage::fake('local');
        $actor = $this->admin();
        $t = $this->createTicket($actor);
        config(['support.evidence.max_size_mb' => 1, 'support.evidence.max_files' => 1]);
        $this->postJson('/v1/admin/support/cases/'.$t->id.'/attachments', ['filename' => 'too-large.pdf', 'content_base64' => base64_encode("%PDF-1.4\n".str_repeat('x', 1048576))])->assertUnprocessable();
        $data = ['filename' => 'valid.pdf', 'content_base64' => base64_encode("%PDF-1.4\n%%EOF")];
        $this->postJson('/v1/admin/support/cases/'.$t->id.'/attachments', $data)->assertOk();
        $this->postJson('/v1/admin/support/cases/'.$t->id.'/attachments', $data)->assertUnprocessable();
    }

    public function test_full_10_mib_pdf_boundary_with_deployable_post_limit(): void
    {
        Storage::fake('local');
        $actor = $this->admin();
        $ticket = $this->createTicket($actor);
        $prefix = "%PDF-1.4\n";
        $content = $prefix.str_repeat('x', 10 * 1048576 - strlen($prefix));
        $data = ['filename' => 'full-size.pdf', 'content_base64' => base64_encode($content)];
        $this->withServerVariables(['CONTENT_LENGTH' => strlen(json_encode($data, JSON_THROW_ON_ERROR))]);
        $this->postJson('/v1/admin/support/cases/'.$ticket->id.'/attachments', $data)->assertOk()->assertJsonPath('data.evidence.0.filename', 'full-size.pdf');
    }

    public function test_central_maker_checker_policy_overrides_environment_fallback(): void
    {
        config(['support.dispute.refund.require_second_approval' => false]);
        $policy = $this->createStub(ConfigurationServiceInterface::class);
        $policy->method('getOrDefault')->willReturnCallback(fn (string $key, mixed $default) => $key === 'support.dispute.refund.require_second_approval' ? true : $default);
        $this->app->when(AdminDisputeRefundService::class)->needs(ConfigurationServiceInterface::class)->give(fn () => $policy);
        $actor = $this->admin();
        [$d,$original] = $this->refundFixture($actor);
        $this->refundRequest($actor, $d)->assertOk()->assertJsonPath('data.refund_pending_confirmation', true);
        $this->assertSame(0, DB::connection('platform')->table('transaction.transaction_projections')->where('parent_transaction_id', $original)->count());
    }

    public function test_original_customer_wallet_ownership_and_status_cannot_be_bypassed(): void
    {
        $actor = $this->admin();
        [$d,$original] = $this->refundFixture($actor);
        $owner = $actor;
        $wallet = $this->createStub(WalletModuleContract::class);
        $wallet->method('ownerActorId')->willReturnCallback(static function () use (&$owner): string {
            return $owner;
        });
        $wallet->method('getPocketLedgerAccountRef')->willReturn('wallet:eligibility-test');
        $this->instance(WalletModuleContract::class, $wallet);
        $table = DB::connection('platform')->table('transaction.transaction_projections')->where('id', $original);
        $table->update(['customer_id' => (string) Uuid::uuid7()]);
        $this->refundRequest($actor, $d)->assertUnprocessable();
        $table->update(['customer_id' => $actor, 'status' => 'failed']);
        $this->refundRequest($actor, $d)->assertUnprocessable();
        $table->update(['status' => 'completed']);
        // The controller retains its injected contract: vary the authoritative lookup result.
        $owner = (string) Uuid::uuid7();
        $this->refundRequest($actor, $d)->assertUnprocessable();
        $this->assertSame('approved', $d->fresh()->status->value);
        $this->assertSame(0, DB::connection('platform')->table('transaction.transaction_projections')->where('parent_transaction_id', $original)->count());
    }
}
