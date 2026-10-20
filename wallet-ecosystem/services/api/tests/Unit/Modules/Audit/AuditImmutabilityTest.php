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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AuditImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    private AuditService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(AuditService::class);
        config(['audit.hmac_key' => 'test-hmac-immutability']);
    }

    private function seedEvent(string $correlationId): AuditEventModel
    {
        $id = $this->service->record(AuditEntry::make(
            category:      AuditEventCategory::Wallet,
            eventType:     'wallet.created',
            actorType:     AuditActorType::System,
            action:        'Test wallet created',
            outcome:       AuditOutcome::Success,
            correlationId: $correlationId,
        ));

        return AuditEventModel::find($id);
    }

    public function test_save_on_existing_model_throws_immutability_exception(): void
    {
        $model = $this->seedEvent('corr-immut-001');

        $this->expectException(AuditImmutabilityViolationException::class);
        $model->action = 'tampered';
        $model->save();
    }

    public function test_delete_throws_immutability_exception(): void
    {
        $model = $this->seedEvent('corr-immut-002');

        $this->expectException(AuditImmutabilityViolationException::class);
        $model->delete();
    }

    public function test_tamper_attempt_records_security_event(): void
    {
        $model = $this->seedEvent('corr-immut-003');

        try {
            $model->action = 'tampered';
            $model->save();
        } catch (AuditImmutabilityViolationException) {
            // expected
        }

        $tamper = AuditEventModel::where('event_type', 'security.tamper.attempt')
            ->where('entity_id', $model->id)
            ->first();

        $this->assertNotNull($tamper, 'A security.tamper.attempt event should be recorded');
        $this->assertSame('security', $tamper->event_category);
        $this->assertSame('denied', $tamper->outcome);
    }
}
