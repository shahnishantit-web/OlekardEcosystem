<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Transaction\Application\Services\TransactionModuleService;
use Database\Seeders\DevAdminDemoDataSeeder;
use Database\Seeders\DevAdminFullPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\TestCase;

final class AdminPageRepairTest extends TestCase
{
    protected $connectionsToTransact = ['platform', 'identity'];

    public function test_verified_jwt_populates_the_laravel_request_user(): void
    {
        Route::get('/v1/test/auth-context', fn (\Illuminate\Http\Request $request) => response()->json([
            'actor_id' => $request->attributes->get('identity.user_id'), 'user_id' => $request->user()?->id,
        ]))->middleware('identity.auth');
        $login = $this->postJson('/v1/auth/login', [
            'identifier' => 'admin@olekard.local', 'credential' => 'Admin123!',
            'credential_type' => 'password', 'device_fingerprint' => 'admin-web',
        ])->assertOk();
        $token = $login->json('data.access_token');
        $this->assertIsString($token);
        $id = DB::connection('identity')->table('identity.users')->where('email', 'admin@olekard.local')->value('id');
        $this->withToken($token)->getJson('/v1/test/auth-context')->assertOk()
            ->assertJsonPath('user_id', $id)->assertJsonPath('actor_id', $id);
        $this->withToken('invalid-token')->getJson('/v1/test/auth-context')->assertUnauthorized();
    }

    public function test_local_admin_seeder_repairs_new_route_permissions_idempotently(): void
    {
        Route::get('/v1/test/admin-permission', fn () => null)
            ->middleware('identity.require-permission:qa.repair_regression.read');
        $this->seed(DevAdminFullPermissionsSeeder::class);
        $this->seed(DevAdminFullPermissionsSeeder::class);
        $db = DB::connection('identity');
        $permission = $db->table('identity.permissions')->where('slug', 'qa.repair_regression.read')->first();
        $this->assertNotNull($permission);
        $this->assertNotEmpty($permission->display_name);
        $role = $db->table('identity.roles')->where('slug', 'admin')->value('id');
        $this->assertSame(1, $db->table('identity.role_permissions')->where('role_id', $role)->where('permission_id', $permission->id)->count());
    }

    public function test_full_admin_seeder_does_not_run_in_production(): void
    {
        $before = DB::connection('identity')->table('identity.role_permissions')->count();
        app()->instance('env', 'production');
        try {
            (new DevAdminFullPermissionsSeeder())->run();
            $this->assertSame($before, DB::connection('identity')->table('identity.role_permissions')->count());
        } finally {
            app()->instance('env', 'testing');
        }
    }

    public function test_compliance_transaction_filters_and_cursor_do_not_leak_other_users(): void
    {
        $owner = (string) Str::uuid7();
        foreach ([$owner, $owner, (string) Str::uuid7()] as $index => $customer) {
            DB::connection('platform')->table('transaction.transaction_projections')->insert([
                'id' => (string) Str::uuid7(), 'reference' => (string) Str::uuid7(),
                'type' => 'transfer', 'status' => 'pending', 'amount' => 100 + $index,
                'currency_code' => 'BWP', 'direction' => 'debit', 'wallet_id' => (string) Str::uuid7(),
                'customer_id' => $customer, 'source_module' => 'transfer',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $service = app(TransactionModuleService::class);
        $first = $service->listForCompliance(['user_id' => $owner], null, 1);
        $this->assertCount(1, $first['data']);
        $this->assertSame($owner, $first['data'][0]['user_id']);
        $this->assertSame('transfer', $first['data'][0]['transaction_type']);
        $this->assertNotNull($first['next_cursor']);
        $second = $service->listForCompliance(['user_id' => $owner], $first['next_cursor'], 1);
        $this->assertSame($owner, $second['data'][0]['user_id']);
        $this->assertNotSame($first['data'][0]['transaction_id'], $second['data'][0]['transaction_id']);
        $this->assertNull($second['next_cursor']);
        $this->assertSame([], $service->listForCompliance(['user_id' => $owner, 'outcome' => 'failed'], null, 10)['data']);
    }

    public function test_ledger_history_uses_posted_lines_and_preserves_filters_and_balances(): void
    {
        $ledger = app(LedgerModuleContract::class);
        $asset = (string) Str::uuid7();
        $pocket = (string) Str::uuid7();
        $owner = (string) Str::uuid7();
        $reference = 'TEST-REPAIR-'.Str::uuid7();
        $accounts = app(\App\Modules\Ledger\Application\Services\LedgerAccountService::class);
        $accounts->registerAccount($asset, 'asset', 'BWP', $owner, 'system_float', $owner, (string) Str::uuid7());
        $accounts->registerAccount($pocket, 'liability', 'BWP', $owner, 'customer_pocket', $owner, (string) Str::uuid7());
        $ledger->postEntry([
            'idempotency_key' => (string) Str::uuid7(), 'correlation_id' => (string) Str::uuid7(),
            'currency_code' => 'BWP', 'entry_type' => 'credit', 'transaction_reference' => $reference,
            'lines' => [
                ['ledger_account_reference' => $asset, 'direction' => 'debit', 'amount' => 500],
                ['ledger_account_reference' => $pocket, 'direction' => 'credit', 'amount' => 500],
            ],
        ]);
        $this->assertCount(2, $ledger->getTransactionHistory($reference));
        $this->assertSame([], $ledger->getTransactionHistory('TEST-NO-POSTED-JOURNAL'));
        $this->assertSame(1, $ledger->getAccountHistory($pocket, 1, 20)['meta']['total']);
        $this->assertSame(0, $ledger->getAccountHistory($pocket, 1, 20, ['from' => '2100-01-01'])['meta']['total']);
        $this->assertSame(0, $ledger->getAccountHistory($pocket, 1, 20, ['entry_type' => 'fee'])['meta']['total']);
        $this->assertSame(500, $ledger->getBalance($pocket));
    }

    public function test_demo_data_seeder_is_idempotent_and_does_not_post_financial_entries(): void
    {
        $tables = ['ledger.journals', 'ledger.journal_lines', 'transaction.transaction_projections',
            'support.support_tickets', 'kyc.kyc_cases', 'risk.risk_alerts', 'security.vulnerability_findings'];
        $counts = fn () => array_combine($tables, array_map(fn ($table) => DB::connection('platform')->table($table)->count(), $tables));
        $before = $counts();
        $policiesBefore = DB::connection('platform')->table('notification.admin_rules as r')
            ->join('notification.notification_templates as t', 't.id', '=', 'r.template_id')
            ->where('t.key', '!=', 'local-demo')->orderBy('r.template_id')->orderBy('r.channel')->get(['r.*'])->toJson();
        $this->seed(DevAdminDemoDataSeeder::class);
        $once = $counts();
        $this->seed(DevAdminDemoDataSeeder::class);
        $this->assertSame($once, $counts());
        $this->assertSame($before['ledger.journals'], $once['ledger.journals']);
        $this->assertSame($before['ledger.journal_lines'], $once['ledger.journal_lines']);
        $this->assertSame($policiesBefore, DB::connection('platform')->table('notification.admin_rules as r')
            ->join('notification.notification_templates as t', 't.id', '=', 'r.template_id')
            ->where('t.key', '!=', 'local-demo')->orderBy('r.template_id')->orderBy('r.channel')->get(['r.*'])->toJson());
    }

    public function test_dashboard_refresh_reads_current_module_tables_and_returns_financial_ui_fields(): void
    {
        \Illuminate\Support\Facades\Artisan::call('adminapp:refresh-dashboard-cache');
        $operational = json_decode(\Illuminate\Support\Facades\Redis::get('adminapp:dashboard:operational'), true);
        $financial = json_decode(\Illuminate\Support\Facades\Redis::get('adminapp:dashboard:financial'), true);
        $this->assertSame(DB::connection('platform')->table('kyc.kyc_cases')->where('status', 'under_review')->count(), $operational['pending_kyc_count']);
        $this->assertSame(DB::connection('platform')->table('transaction.transaction_projections')->where('created_at', '>=', now()->startOfDay())->count(), $financial['transactions_today_count']);
        $resource = new \App\Modules\AdminApp\Infrastructure\Http\Resources\FinancialDashboardResource($financial);
        $data = $resource->toArray(request());
        foreach (['currency', 'transactions_today_count', 'transactions_today_volume_minor_units', 'pending_settlements_minor_units', 'failed_transactions_today'] as $key) {
            $this->assertArrayHasKey($key, $data);
        }
        $this->assertSame('BWP', $data['currency']);
    }
}
