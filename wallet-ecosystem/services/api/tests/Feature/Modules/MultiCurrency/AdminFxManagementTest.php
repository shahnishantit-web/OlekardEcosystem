<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\MultiCurrency;

use App\Modules\MultiCurrency\Application\Services\AdminFxManagementService;
use App\Modules\MultiCurrency\Domain\Contracts\FxRateProviderInterface;
use App\Modules\MultiCurrency\Domain\Contracts\FxRateRepositoryInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use Illuminate\Database\Connectors\PostgresConnector;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Ramsey\Uuid\Uuid;
use Tests\Support\FakeRedisConnection;
use Tests\TestCase;

final class AdminFxManagementTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        app('cache')->forgetDriver('redis');
        config(['cache.default' => 'array']);
        $m = app('redis');
        $p = new \ReflectionProperty($m, 'connections');
        $c = $p->getValue($m);
        $c['identity'] = new FakeRedisConnection;
        $p->setValue($m, $c);
        Queue::fake();
        foreach (['BWP', 'USD'] as $code) {
            DB::connection('platform')->table('multi_currency.currencies')->updateOrInsert(['code' => $code], ['id' => (string) Uuid::uuid7(), 'name' => $code, 'symbol' => $code, 'decimal_places' => 2, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function admin(array $p = ['multi_currency.manage_currencies', 'multi_currency.configure_rate', 'configuration.approve']): string
    {
        $a = $this->createAuthenticatedAdmin($p);
        $this->actingAs($a);

        return $a->id;
    }

    private function rate(array $extra = []): array
    {
        return array_merge(['from_currency_code' => 'BWP', 'to_currency_code' => 'USD', 'rate' => '0.07500000', 'spread' => '0.01000', 'mode' => 'manual'], $extra);
    }

    private function submit(array $extra = []): string
    {
        return $this->withHeader('Idempotency-Key', (string) Uuid::uuid7())->postJson('/v1/admin/fx/rates', $this->rate($extra))->assertCreated()->json('data.id');
    }

    public function test_currency_list_has_precision_and_base_and_status(): void
    {
        $this->admin();
        $this->getJson('/v1/admin/currencies')->assertOk()->assertJsonStructure(['data' => [['code', 'name', 'decimal_places', 'is_active', 'is_base']]]);
    }

    public function test_currency_status_requires_distinct_checker_and_replays_safely(): void
    {
        $maker = $this->admin();
        $key = (string) Uuid::uuid7();
        $r = $this->withHeader('Idempotency-Key', $key)->patchJson('/v1/admin/currencies/USD', ['is_active' => false])->assertOk()->json('data.pending_change_request_id');
        $this->assertTrue((bool) DB::connection('platform')->table('multi_currency.currencies')->where('code', 'USD')->value('is_active'));
        $this->withHeader('Idempotency-Key', $key)->patchJson('/v1/admin/currencies/USD', ['is_active' => false])->assertOk()->assertJsonPath('data.pending_change_request_id', $r);
        $this->withHeader('Idempotency-Key', (string) Uuid::uuid7())->postJson("/v1/admin/currencies/USD/activate/$r")->assertStatus(422);
        $this->admin();
        $this->withHeader('Idempotency-Key', (string) Uuid::uuid7())->postJson("/v1/admin/currencies/USD/activate/$r")->assertOk()->assertJsonPath('data.is_active', false);
    }

    public function test_rate_approval_manual_lock_idempotency_and_history(): void
    {
        $this->admin();
        $key = (string) Uuid::uuid7();
        $id = $this->withHeader('Idempotency-Key', $key)->postJson('/v1/admin/fx/rates', $this->rate())->assertCreated()->json('data.id');
        $this->withHeader('Idempotency-Key', $key)->postJson('/v1/admin/fx/rates', $this->rate())->assertCreated()->assertJsonPath('data.id', $id);
        $this->withHeader('Idempotency-Key', $key)->postJson('/v1/admin/fx/rates', $this->rate(['rate' => '0.08']))->assertStatus(422);
        $this->withHeader('Idempotency-Key', (string) Uuid::uuid7())->postJson("/v1/admin/fx/rates/$id/activate")->assertStatus(422);
        $this->admin();
        $this->withHeader('Idempotency-Key', (string) Uuid::uuid7())->postJson("/v1/admin/fx/rates/$id/activate")->assertOk()->assertJsonPath('data.source', 'manual')->assertJsonPath('data.status', 'active');
        $this->getJson('/v1/admin/fx/rates/BWP-USD/history')->assertOk()->assertJsonStructure(['data' => ['chart', 'history', 'pagination']]);
    }

    public function test_provider_unconfigured_fails_closed(): void
    {
        $this->admin();
        $this->withHeader('Idempotency-Key', (string) Uuid::uuid7())->postJson('/v1/admin/fx/rates', $this->rate(['mode' => 'provider']))->assertStatus(503);
    }

    public function test_invalid_decimal_and_missing_key_rejected(): void
    {
        $this->admin();
        $this->postJson('/v1/admin/fx/rates', $this->rate())->assertStatus(422);
        foreach (['0', '-1', '1e3', '0.123456789', '10000000000'] as $v) {
            $this->withHeader('Idempotency-Key', (string) Uuid::uuid7())->postJson('/v1/admin/fx/rates', $this->rate(['rate' => $v]))->assertStatus(422);
        }
    }

    public function test_read_permission_and_checker_permission_enforced(): void
    {
        $this->admin([]);
        $this->getJson('/v1/admin/fx/rates')->assertForbidden();
        $this->getJson('/v1/admin/currencies')->assertForbidden();
        $this->admin(['multi_currency.configure_rate']);
        $id = $this->submit();
        $this->withHeader('Idempotency-Key', (string) Uuid::uuid7())->postJson("/v1/admin/fx/rates/$id/activate")->assertForbidden();
    }

    public function test_provider_revert_and_ingestion_respects_manual_lock(): void
    {
        $this->app->bind(FxRateProviderInterface::class, fn () => new class implements FxRateProviderInterface
        {
            public function quote(string $from, string $to): array
            {
                return ['rate' => '0.07700000', 'provider' => 'approved-test-adapter', 'observed_at' => now()->subSecond()->toIso8601String()];
            }
        });
        $this->admin();
        $id = $this->submit();
        $this->admin();
        $this->withHeader('Idempotency-Key', (string) Uuid::uuid7())->postJson("/v1/admin/fx/rates/$id/activate")->assertOk();
        $service = app(AdminFxManagementService::class);
        $this->assertFalse($service->ingestProviderRate('BWP', 'USD', '0.09', 'approved-test-adapter', now()->toIso8601String(), (string) Uuid::uuid7()));
        $this->admin();
        $providerId = $this->submit(['mode' => 'provider']);
        $this->admin();
        $this->withHeader('Idempotency-Key', (string) Uuid::uuid7())->postJson("/v1/admin/fx/rates/$providerId/activate")->assertOk()->assertJsonPath('data.source', 'provider')->assertJsonPath('data.provider', 'approved-test-adapter');
        $this->assertTrue($service->ingestProviderRate('BWP', 'USD', '0.078', 'approved-test-adapter', now()->toIso8601String(), (string) Uuid::uuid7()));
        $this->assertFalse($service->ingestProviderRate('BWP', 'USD', '0.01', 'different-adapter', now()->toIso8601String(), (string) Uuid::uuid7()));
        $this->getJson('/v1/admin/fx/rates/BWP-USD/history?per_page=1')->assertOk()->assertJsonPath('data.pagination.total', 3)->assertJsonCount(1, 'data.history')->assertJsonCount(3, 'data.chart');
    }

    public function test_legacy_activation_cannot_bypass_e28(): void
    {
        $this->admin();
        $id = $this->submit();
        $this->admin();
        $this->withHeader('Idempotency-Key', (string) Uuid::uuid7())->postJson("/v1/admin/multi-currency/fx-rates/$id/activate")->assertStatus(422);
    }

    public function test_currency_financial_legacy_bypass_and_precision_rejected(): void
    {
        $this->admin();
        $this->patchJson('/v1/admin/multi-currency/currencies/USD', ['is_active' => false])->assertStatus(422);
        $this->patchJson('/v1/admin/currencies/USD', ['decimal_places' => 8])->assertStatus(422);
        $this->postJson('/v1/admin/multi-currency/currencies', ['code' => 'USD', 'name' => 'Changed', 'symbol' => 'X', 'decimal_places' => 2, 'is_active' => true])->assertStatus(422);
    }

    public function test_future_rate_is_not_active_and_pending_excluded_from_history(): void
    {
        $this->admin();
        $id = $this->submit(['effective_from' => now()->addDay()->toIso8601String()]);
        $this->admin();
        $this->withHeader('Idempotency-Key', (string) Uuid::uuid7())->postJson("/v1/admin/fx/rates/$id/activate")->assertStatus(422);
        $this->getJson('/v1/admin/fx/rates/BWP-USD/history')->assertOk()->assertJsonPath('data.pagination.total', 0);
    }

    public function test_inactive_pairs_not_quoted_or_listed(): void
    {
        $this->admin();
        $id = $this->submit();
        $this->admin();
        $this->withHeader('Idempotency-Key', (string) Uuid::uuid7())->postJson("/v1/admin/fx/rates/$id/activate")->assertOk();
        DB::connection('platform')->table('multi_currency.currencies')->where('code', 'USD')->update(['is_active' => false]);
        $this->getJson('/v1/admin/fx/rates')->assertOk()->assertJsonCount(0, 'data');
        $this->assertNull(app(FxRateRepositoryInterface::class)->findActivePair('BWP', 'USD'));
    }

    public function test_history_validation_and_old_rates_pagination(): void
    {
        $this->admin();
        $id = $this->submit();
        $this->admin();
        $this->withHeader('Idempotency-Key', (string) Uuid::uuid7())->postJson("/v1/admin/fx/rates/$id/activate")->assertOk();
        DB::connection('platform')->table('multi_currency.fx_rates')->where('id', $id)->update(['effective_from' => now()->subDays(31)]);
        $this->getJson('/v1/admin/fx/rates/BWP-USD/history')->assertOk()->assertJsonCount(0, 'data.chart')->assertJsonPath('data.pagination.total', 1);
        $this->getJson('/v1/admin/fx/rates/bad/history')->assertStatus(422);
        $this->getJson('/v1/admin/fx/rates/BWP-USD/history?per_page=101')->assertStatus(422);
    }

    public function test_applied_currency_request_cannot_undo_a_later_decision(): void
    {
        $this->admin();
        $a = $this->withHeader('Idempotency-Key', (string) Uuid::uuid7())->patchJson('/v1/admin/currencies/USD', ['is_active' => false])->assertOk()->json('data.pending_change_request_id');
        $this->admin();
        $this->withHeader('Idempotency-Key', (string) Uuid::uuid7())->postJson("/v1/admin/currencies/USD/activate/$a")->assertOk()->assertJsonPath('data.is_active', false);
        $this->admin();
        $b = $this->withHeader('Idempotency-Key', (string) Uuid::uuid7())->patchJson('/v1/admin/currencies/USD', ['is_active' => true])->assertOk()->json('data.pending_change_request_id');
        $this->admin();
        $this->withHeader('Idempotency-Key', (string) Uuid::uuid7())->postJson("/v1/admin/currencies/USD/activate/$b")->assertOk()->assertJsonPath('data.is_active', true);
        $this->withHeader('Idempotency-Key', (string) Uuid::uuid7())->postJson("/v1/admin/currencies/USD/activate/$a")->assertOk()->assertJsonPath('data.is_active', true);
        $this->assertSame(2, DB::connection('platform')->table('multi_currency.currency_change_applications')->count());
    }

    public function test_approved_payload_tampering_is_rejected_atomically(): void
    {
        $this->admin();
        $id = $this->submit();
        DB::connection('platform')->table('multi_currency.fx_rates')->where('id', $id)->update(['rate' => '99']);
        $this->admin();
        $this->withHeader('Idempotency-Key', (string) Uuid::uuid7())->postJson("/v1/admin/fx/rates/$id/activate")->assertStatus(422);
        $this->assertSame('pending_approval', DB::connection('platform')->table('multi_currency.fx_rates')->where('id', $id)->value('status'));
    }

    public function test_pair_and_idempotency_locks_contend_across_connections(): void
    {
        $this->admin();
        $key = (string) Uuid::uuid7();
        $this->withHeader('Idempotency-Key', $key)->postJson('/v1/admin/fx/rates', $this->rate())->assertCreated();
        $pdo = (new PostgresConnector)->connect(DB::connection('platform')->getConfig());
        $pdo->beginTransaction();
        try {
            foreach (['fx-pair:BWP-USD', 'fx-idem:'.$key] as $lock) {
                $s = $pdo->prepare('SELECT pg_try_advisory_xact_lock(hashtextextended(?,0))');
                $s->execute([$lock]);
                $this->assertFalse($s->fetchColumn(), 'Competing connection must not acquire held configuration lock.');
            }
        } finally {
            $pdo->rollBack();
        }
    }

    public function test_currency_status_audit_and_repeated_rate_versions(): void
    {
        $this->admin();
        $id = $this->submit();
        $this->admin();
        $this->withHeader('Idempotency-Key', (string) Uuid::uuid7())->postJson("/v1/admin/fx/rates/$id/activate")->assertOk();
        $this->admin();
        $second = $this->submit(['rate' => '0.076']);
        $this->admin();
        $this->withHeader('Idempotency-Key', (string) Uuid::uuid7())->postJson("/v1/admin/fx/rates/$second/activate")->assertOk();
        $this->assertSame([1, 2], DB::connection('platform')->table('platform.configuration_values')->where('config_key', 'multi_currency.fx_rate.BWP_USD')->orderBy('version')->pluck('version')->all());
        $this->assertTrue(DB::connection('platform')->table('platform.audit_events')->where('action', 'multi_currency.fx_rate.activated')->exists());
        $this->assertSame(1, DB::connection('platform')->table('multi_currency.fx_rates')->where('from_currency_code', 'BWP')->where('to_currency_code', 'USD')->where('status', 'active')->count());
    }

    public function test_unavailable_provider_does_not_expose_internal_errors(): void
    {
        $this->app->bind(FxRateProviderInterface::class, fn () => new class implements FxRateProviderInterface
        {
            public function quote(string $from, string $to): array
            {
                throw new \RuntimeException('secret-auth-key-in-provider-error');
            }
        });
        $this->admin();
        $response = $this->withHeader('Idempotency-Key', (string) Uuid::uuid7())->postJson('/v1/admin/fx/rates', $this->rate(['mode' => 'provider']))->assertStatus(503);
        $this->assertStringNotContainsString('secret-auth-key', $response->getContent());
    }

    public function test_base_currency_resolves_from_approved_configuration_envelope(): void
    {
        DB::connection('platform')->table('platform.configuration_values')->updateOrInsert(['config_key' => 'multi_currency.base_currency', 'version' => 1], ['id' => (string) Uuid::uuid7(), 'config_value' => json_encode(['value' => 'BWP']), 'value_type' => 'json', 'status' => 'active', 'effective_from' => now(), 'created_at' => now(), 'updated_at' => now()]);
        app(ConfigurationServiceInterface::class)->invalidate('multi_currency.base_currency');
        $this->admin();
        $data = $this->getJson('/v1/admin/currencies')->assertOk()->json('data');
        $rows = collect($data)->keyBy('code');
        $this->assertTrue($rows['BWP']['is_base']);
        $this->assertFalse($rows['USD']['is_base']);
    }

    public function test_currency_request_payload_conflict_and_unknown_currency(): void
    {
        $this->admin();
        $key = (string) Uuid::uuid7();
        $this->withHeader('Idempotency-Key', $key)->patchJson('/v1/admin/currencies/USD', ['is_active' => false])->assertOk();
        $this->withHeader('Idempotency-Key', $key)->patchJson('/v1/admin/currencies/USD', ['is_active' => true])->assertStatus(422);
        $this->withHeader('Idempotency-Key', (string) Uuid::uuid7())->patchJson('/v1/admin/multi-currency/currencies/ZZZ', ['is_active' => true])->assertNotFound();
    }

    public function test_explicit_effective_date_conflict_is_rejected_on_both_apis(): void
    {
        $this->admin();
        $key = (string) Uuid::uuid7();
        $date = now()->subMinute()->toIso8601String();
        $data = $this->rate(['effective_from' => $date]);
        $this->withHeader('Idempotency-Key', $key)->postJson('/v1/admin/fx/rates', $data)->assertCreated();
        $this->withHeader('Idempotency-Key', $key)->postJson('/v1/admin/fx/rates', $data)->assertCreated();
        $data['effective_from'] = now()->addDay()->toIso8601String();
        $this->withHeader('Idempotency-Key', $key)->postJson('/v1/admin/fx/rates', $data)->assertStatus(422);
        $this->withHeader('Idempotency-Key', $key)->postJson('/v1/admin/multi-currency/fx-rates', $data)->assertStatus(422);
    }

    public function test_provider_refresh_replay_returns_original_proposal_without_refetch(): void
    {
        $provider = new class implements FxRateProviderInterface
        {
            public int $calls = 0;

            public function quote(string $from, string $to): array
            {
                $this->calls++;

                return ['rate' => '0.077', 'provider' => 'test-provider', 'observed_at' => now()->subSecond()->toIso8601String()];
            }
        };
        $this->app->instance(FxRateProviderInterface::class, $provider);
        $maker = $this->createAuthenticatedAdmin(['multi_currency.configure_rate']);
        $this->actingAs($maker);
        $this->withHeader('X-Test-User-Id', $maker->id);
        $key = (string) Uuid::uuid7();
        $id = $this->withHeader('Idempotency-Key', $key)->postJson('/v1/admin/fx/rates', $this->rate(['mode' => 'provider']))->assertCreated()->json('data.id');
        $this->admin();
        $this->withHeader('Idempotency-Key', (string) Uuid::uuid7())->postJson("/v1/admin/fx/rates/$id/activate")->assertOk();
        $this->assertTrue(app(AdminFxManagementService::class)->ingestProviderRate('BWP', 'USD', '0.078', 'test-provider', now()->toIso8601String(), (string) Uuid::uuid7()));
        $this->actingAs($maker);
        $this->withHeader('X-Test-User-Id', $maker->id);
        $this->withHeader('Idempotency-Key', $key)->postJson('/v1/admin/fx/rates', $this->rate(['mode' => 'provider']))->assertCreated()->assertJsonPath('data.id', $id)->assertJsonPath('data.rate', '0.07700000');
        $this->assertSame(1, $provider->calls);
    }

    public function test_legacy_activation_requires_uuid_key_even_for_approved_rate(): void {
        $this->admin();$id=$this->submit();$this->admin();
        $this->withHeader('Idempotency-Key',(string)Uuid::uuid7())->postJson("/v1/admin/fx/rates/$id/activate")->assertOk();
        $this->withHeader('Idempotency-Key','')->postJson("/v1/admin/multi-currency/fx-rates/$id/activate")->assertStatus(422);
        $this->withHeader('Idempotency-Key',(string)Uuid::uuid7())->postJson("/v1/admin/multi-currency/fx-rates/$id/activate")->assertOk();
    }

}
