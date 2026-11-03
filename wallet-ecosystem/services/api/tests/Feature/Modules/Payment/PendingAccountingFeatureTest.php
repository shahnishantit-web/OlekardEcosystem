<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Payment;

use App\Modules\Payment\Domain\Contracts\PaymentIntentRepositoryInterface;
use App\Modules\Payment\Domain\Enums\PaymentAccountingStatus;
use App\Modules\Payment\Domain\Enums\PaymentIntentStatus;
use App\Modules\Payment\Infrastructure\Models\PaymentIntentModel;
use Illuminate\Support\Collection;
use Mockery;
use Tests\TestCase;

final class PendingAccountingFeatureTest extends TestCase
{
    public function test_pending_accounting_route_requires_authentication(): void
    {
        $this->getJson('/api/v1/admin/payment/intents/pending-accounting')
            ->assertUnauthorized();
    }

    public function test_pending_accounting_route_requires_admin_permission(): void
    {
        $this->actingAsAdminWithPermission('payment.providers.read')
            ->getJson('/api/v1/admin/payment/intents/pending-accounting')
            ->assertForbidden();
    }

    public function test_authorized_operator_receives_only_unresolved_accounting_rows(): void
    {
        $intent = new PaymentIntentModel([
            'id' => 'p08-pending-intent',
            'status' => PaymentIntentStatus::Succeeded,
            'accounting_status' => PaymentAccountingStatus::PendingLedger,
            'organisation_id' => 'p08-organisation',
            'currency_code' => 'BWP',
            'amount_minor' => 1250,
            'succeeded_at' => now(),
            'accounting_repair_attempts' => 2,
        ]);

        $repository = Mockery::mock(PaymentIntentRepositoryInterface::class);
        $repository->shouldReceive('findAllUnresolvedAccounting')
            ->once()
            ->with(null, 100)
            ->andReturn(new Collection([$intent]));
        $this->app->instance(PaymentIntentRepositoryInterface::class, $repository);

        $this->actingAsAdminWithPermission('payment.intents.admin')
            ->getJson('/api/v1/admin/payment/intents/pending-accounting')
            ->assertOk()
            ->assertJsonPath('data.0.accounting_status', PaymentAccountingStatus::PendingLedger->value)
            ->assertJsonPath('data.0.id', 'p08-pending-intent')
            ->assertJsonPath('meta.limit', 100);
    }
}
