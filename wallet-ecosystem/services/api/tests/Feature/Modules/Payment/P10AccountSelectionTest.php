<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Payment;

use App\Modules\Payment\Application\PaymentOrchestrationService;
use App\Modules\Payment\Domain\Contracts\InitiatesPayment;
use App\Modules\Payment\Domain\Enums\PaymentAttemptStatus;
use App\Modules\Payment\Domain\ValueObjects\PaymentAttemptResult;
use App\Modules\Payment\Infrastructure\Models\PaymentAttemptModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Modules\Integration\IntegrationTestCase;
use Tests\Support\P10PaymentFixture;

final class P10AccountSelectionTest extends IntegrationTestCase
{
    use P10PaymentFixture;
    protected $connectionsToTransact = [null, 'identity', 'platform', 'payment'];

    private function submit(array $changes = [], bool $allowed = false): array
    {
        [$intent, $provider, $account] = $this->paymentFixture();
        if (isset($changes['organisations'])) { $account->enabled_organisations = $changes['organisations']; $account->save(); }
        $adapter = $this->createMock(InitiatesPayment::class);
        $adapter->expects($allowed ? $this->once() : $this->never())->method('initiatePayment')
            ->willReturn(new PaymentAttemptResult(status: PaymentAttemptStatus::Processing));
        Queue::fake();
        $this->app->instance('payment.adapter.'.$provider->code, $adapter);
        $this->app->instance(AuditServiceInterface::class, $this->createMock(AuditServiceInterface::class));
        $request = ['payment_intent_id' => $intent->id, 'provider_code' => $provider->code,
            'payer_actor_id' => $intent->payer_actor_id, 'idempotency_key' => $intent->id, 'correlation_id' => $intent->correlation_id];
        if (isset($changes['selected_id'])) $request['provider_account_id'] = $changes['selected_id'];
        app(PaymentOrchestrationService::class)->initiateAttempt($request);
        return [$intent, $account];
    }

    public function test_omitted_account_is_selected_from_authoritative_context_and_version(): void
    {
        [$intent, $account] = $this->submit(allowed: true);
        $attempt = PaymentAttemptModel::where('payment_intent_id', $intent->id)->sole();
        $this->assertSame($account->id, $attempt->provider_account_id);
        $this->assertSame($account->fresh()->config_version, $attempt->provider_account_version);
    }

    public function test_cross_organisation_account_cannot_execute(): void
    {
        $this->expectException(ValidationException::class);
        $this->submit(['organisations' => [(string) \Ramsey\Uuid\Uuid::uuid7()]]);
    }

    public function test_unrecognised_selected_account_cannot_execute(): void
    {
        $this->expectException(ValidationException::class);
        $this->submit(['selected_id' => (string) \Ramsey\Uuid\Uuid::uuid7()]);
    }

    public function test_live_processing_remains_disabled(): void
    {
        $this->assertFalse(config('payment.live_callback_processing_enabled', false));
    }
}
