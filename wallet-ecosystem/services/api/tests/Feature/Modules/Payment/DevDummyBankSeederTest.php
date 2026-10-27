<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Payment;

use App\Modules\Payment\Infrastructure\Models\PaymentProviderModel;
use Database\Seeders\DevDummyBankSeeder;
use Tests\Feature\Modules\Integration\IntegrationTestCase;

final class DevDummyBankSeederTest extends IntegrationTestCase
{
    protected $connectionsToTransact = [null, 'identity', 'platform', 'payment'];

    public function test_authorized_bank_list_returns_the_dummy_banks(): void
    {
        (new DevDummyBankSeeder())->run();
        $admin = $this->makeAdminWithPermission('payment.configuration');
        $response = $this->withActorToken($admin)->getJson('/api/v1/admin/payments/banks?code=bank&status=draft');
        $response->assertOk();
        $codes = array_column($response->json('data'), 'code');
        foreach (['bank1', 'bank2', 'bank3'] as $code) $this->assertContains($code, $codes);
    }

    public function test_seeding_is_repeatable_and_keeps_banks_disabled_without_accounts(): void
    {
        $seeder = new DevDummyBankSeeder();
        $seeder->run();
        $first = PaymentProviderModel::whereIn('code', ['bank1','bank2','bank3'])->orderBy('code')->get();
        $seeder->run();
        $second = PaymentProviderModel::whereIn('code', ['bank1','bank2','bank3'])->orderBy('code')->get();
        $this->assertCount(3, $second);
        $this->assertSame($first->pluck('id')->all(), $second->pluck('id')->all());
        foreach ($second as $bank) {
            $this->assertFalse($bank->is_enabled);
            $this->assertSame('draft', $bank->bank_lifecycle_status);
            $this->assertSame('mock', $bank->environment);
            $this->assertSame(0, $bank->accounts()->count());
        }
        $this->assertFalse(config('payment.live_callback_processing_enabled', false));
    }

    public function test_production_cannot_run_the_dummy_seeder(): void
    {
        $before = PaymentProviderModel::count();
        $this->app->instance('env', 'production');
        (new DevDummyBankSeeder())->run();
        $this->assertSame($before, PaymentProviderModel::count());
    }
}
