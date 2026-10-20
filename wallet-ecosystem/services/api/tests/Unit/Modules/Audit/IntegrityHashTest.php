<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Audit;

use App\Modules\Audit\Application\Services\AuditService;
use App\Modules\Audit\Domain\Enums\AuditActorType;
use App\Modules\Audit\Domain\Enums\AuditEventCategory;
use App\Modules\Audit\Domain\Enums\AuditOutcome;
use App\Modules\Audit\Domain\ValueObjects\AuditEntry;
use App\Modules\Audit\Infrastructure\Models\AuditEventModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class IntegrityHashTest extends TestCase
{
    use RefreshDatabase;

    private AuditService $service;

    protected function setUp(): void
    {
        parent::setUp();
        config(['audit.hmac_key' => 'known-test-key-for-hash']);
        $this->service = $this->app->make(AuditService::class);
    }

    public function test_verify_integrity_returns_true_for_unmodified_record(): void
    {
        $id = $this->service->record(AuditEntry::make(
            category:      AuditEventCategory::Authentication,
            eventType:     'authentication.login.success',
            actorType:     AuditActorType::Customer,
            action:        'Logged in',
            outcome:       AuditOutcome::Success,
            correlationId: 'corr-hash-001',
        ));

        $model = AuditEventModel::find($id);
        $this->assertTrue($model->verifyIntegrity());
    }

    public function test_verify_integrity_returns_false_when_hash_altered(): void
    {
        $id = $this->service->record(AuditEntry::make(
            category:      AuditEventCategory::Admin,
            eventType:     'admin.user.created',
            actorType:     AuditActorType::Admin,
            action:        'Admin created user',
            outcome:       AuditOutcome::Success,
            correlationId: 'corr-hash-002',
        ));

        // Directly tamper with the stored hash via raw SQL (bypasses the Eloquent immutability guard)
        DB::connection('platform')->statement(
            'UPDATE audit.audit_events SET integrity_hash = ? WHERE id = ?',
            ['0000000000000000000000000000000000000000000000000000000000000000', $id]
        );

        $model = AuditEventModel::find($id);
        $this->assertFalse($model->verifyIntegrity());
    }

    public function test_hash_changes_when_hmac_key_changes(): void
    {
        config(['audit.hmac_key' => 'key-one']);
        $id = $this->service->record(AuditEntry::make(
            category:      AuditEventCategory::Kyc,
            eventType:     'kyc.tier.upgraded',
            actorType:     AuditActorType::System,
            action:        'KYC tier upgraded',
            outcome:       AuditOutcome::Success,
            correlationId: 'corr-hash-003',
        ));

        $model = AuditEventModel::find($id);
        $this->assertTrue($model->verifyIntegrity());

        // Switch to different key — same record, different key => should fail
        config(['audit.hmac_key' => 'key-two']);
        $this->assertFalse($model->verifyIntegrity());
    }
}
