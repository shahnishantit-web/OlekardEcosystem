<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Audit;

use App\Modules\Audit\Application\Services\AuditService;
use App\Modules\Audit\Domain\Enums\AuditActorType;
use App\Modules\Audit\Domain\Enums\AuditEventCategory;
use App\Modules\Audit\Domain\Enums\AuditOutcome;
use App\Modules\Audit\Domain\Exceptions\AuditImmutabilityViolationException;
use App\Modules\Audit\Domain\ValueObjects\AuditEntry;
use App\Modules\Audit\Infrastructure\Models\AuditEventModel;
use App\Modules\Platform\Application\Services\OutboxEventPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AuditServiceTest extends TestCase
{
    use RefreshDatabase;

    private AuditService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(AuditService::class);
        config(['audit.hmac_key' => 'test-hmac-key-for-unit-tests']);
    }

    public function test_record_persists_all_fields_and_returns_uuid(): void
    {
        $entry = AuditEntry::make(
            category:      AuditEventCategory::Authentication,
            eventType:     'authentication.login.success',
            actorType:     AuditActorType::Customer,
            action:        'User logged in',
            outcome:       AuditOutcome::Success,
            correlationId: 'corr-unit-001',
        )->withActor('user-uuid-001')->withMetadata(['source_ip' => '127.0.0.1']);

        $id = $this->service->record($entry);

        $this->assertNotEmpty($id);
        $model = AuditEventModel::find($id);
        $this->assertNotNull($model);
        $this->assertSame('authentication', $model->event_category);
        $this->assertSame('authentication.login.success', $model->event_type);
        $this->assertSame('customer', $model->actor_type);
        $this->assertSame('user-uuid-001', $model->actor_id);
        $this->assertSame('success', $model->outcome);
        $this->assertSame('corr-unit-001', $model->correlation_id);
        $this->assertSame(['source_ip' => '127.0.0.1'], $model->metadata);
    }

    public function test_record_computes_non_null_integrity_hash(): void
    {
        $entry = AuditEntry::make(
            category:      AuditEventCategory::Wallet,
            eventType:     'wallet.created',
            actorType:     AuditActorType::System,
            action:        'Wallet created by system',
            outcome:       AuditOutcome::Success,
            correlationId: 'corr-unit-002',
        );

        $id    = $this->service->record($entry);
        $model = AuditEventModel::find($id);

        $this->assertNotNull($model->integrity_hash);
        $this->assertSame(64, strlen($model->integrity_hash));
    }

    public function test_record_within_explicit_transaction_commits_atomically(): void
    {
        $entry = AuditEntry::make(
            category:      AuditEventCategory::Transaction,
            eventType:     'transaction.completed',
            actorType:     AuditActorType::System,
            action:        'Transfer completed',
            outcome:       AuditOutcome::Success,
            correlationId: 'corr-unit-003',
        );

        \Illuminate\Support\Facades\DB::connection('platform')->transaction(
            fn () => $this->service->record($entry)
        );

        $this->assertDatabaseHas('audit.audit_events', ['correlation_id' => 'corr-unit-003'], 'platform');
    }

    public function test_audit_event_model_throws_on_update(): void
    {
        $entry = AuditEntry::make(
            category:      AuditEventCategory::Security,
            eventType:     'security.test',
            actorType:     AuditActorType::System,
            action:        'Test security event',
            outcome:       AuditOutcome::Denied,
            correlationId: 'corr-unit-004',
        );

        $id    = $this->service->record($entry);
        $model = AuditEventModel::find($id);

        $this->expectException(AuditImmutabilityViolationException::class);
        $model->action = 'tampered';
        $model->save();
    }

    public function test_audit_event_model_throws_on_delete(): void
    {
        $entry = AuditEntry::make(
            category:      AuditEventCategory::Security,
            eventType:     'security.test.delete',
            actorType:     AuditActorType::System,
            action:        'Test delete attempt',
            outcome:       AuditOutcome::Denied,
            correlationId: 'corr-unit-005',
        );

        $id    = $this->service->record($entry);
        $model = AuditEventModel::find($id);

        $this->expectException(AuditImmutabilityViolationException::class);
        $model->delete();
    }
}
