<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Payment;

use App\Modules\Integration\Infrastructure\Models\IntegrationModel;
use App\Modules\Payment\Infrastructure\Models\PaymentProviderModel;
use App\Modules\Payment\Infrastructure\Models\PaymentProviderAccountModel;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class P10ConcurrentReceiptTest extends TestCase
{
    public function test_two_real_processes_commit_one_receipt_and_delivery_intent(): void
    {
        if (! function_exists('proc_open')) $this->markTestSkipped('proc_open required for independent PostgreSQL sessions.');
        $id = (string) Uuid::uuid7();
        $providerId = (string) Uuid::uuid7(); $accountId = (string) Uuid::uuid7();
        $slug = 'p10-race-'.substr($id, -12);
        $release = storage_path('framework/p10-race-'.$id.'.ready');
        $processes = [];
        try {
            PaymentProviderModel::create(['id' => $providerId, 'code' => $slug, 'display_name' => 'TEST ONLY',
                'adapter_identifier' => 'mock_bank', 'adapter_version' => 'test', 'environment' => 'mock',
                'is_enabled' => false, 'is_suspended' => true, 'is_default' => false, 'sort_order' => 1]);
            PaymentProviderAccountModel::create(['id' => $accountId, 'provider_id' => $providerId,
                'merchant_account_reference' => 'race-merchant', 'callback_secret_reference' => 'P10_RACE_TEST_SECRET', 'is_active' => false]);
            IntegrationModel::create(['id' => $id, 'provider_type' => 'payment', 'provider_slug' => $slug,
                'display_name' => 'TEST ONLY', 'credentials_reference' => 'P10_RACE_TEST_SECRET', 'status' => 'active',
                'is_default' => false, 'configuration' => ['payment_provider_account_id' => $accountId,
                    'payment_provider_code' => $slug, 'environment' => 'mock']]);
            for ($i = 0; $i < 2; $i++) {
                $pipes = [];
                // Per-command extension settings are not inherited by child PHP.
                // Windows development PHP distributions may ship PostgreSQL disabled.
                $phpOptions = PHP_OS_FAMILY === 'Windows' ? ['-d', 'extension=php_pdo_pgsql.dll', '-d', 'extension=php_pgsql.dll'] : [];
                $process = proc_open([PHP_BINARY, '-d', 'xdebug.mode=off', ...$phpOptions, base_path('tests/Support/P10ReceiptRaceWorker.php'), $slug, $release, $id],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $this->assertIsResource($process);
                fclose($pipes[0]);
                $processes[] = [$process, $pipes];
            }
            touch($release);
            $receipts = [];
            foreach ($processes as [$process, $pipes]) {
                $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]); fclose($pipes[2]);
                $exit = proc_close($process);
                $this->assertSame(0, $exit, 'Worker failed: '.$stderr);
                $this->assertSame(1, preg_match('/RECEIPT=([a-f0-9-]{36})/', $stdout, $match));
                $receipts[] = $match[1];
            }
            $this->assertSame($receipts[0], $receipts[1]);
            $this->assertSame(1, DB::connection('platform')->table('integration.webhook_events')->where('provider_slug', $slug)->count());
            $this->assertSame(1, DB::connection('platform')->table('integration.admin_retry_intents')->where('webhook_event_id', $receipts[0])->count());
        } finally {
            foreach ($processes as [$process, $pipes]) {
                if (is_resource($process)) { proc_terminate($process); proc_close($process); }
                foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
            }
            $receiptIds = DB::connection('platform')->table('integration.webhook_events')->where('provider_slug', $slug)->pluck('id');
            DB::connection('platform')->table('integration.admin_retry_intents')->whereIn('webhook_event_id', $receiptIds)->delete();
            DB::connection('platform')->table('integration.webhook_processing_attempts')->whereIn('webhook_event_id', $receiptIds)->delete();
            foreach ($receiptIds as $receiptId) DB::connection('platform')->table('platform.outbox_events')->where('payload->webhook_event_id', $receiptId)->delete();
            DB::connection('platform')->table('integration.webhook_events')->whereIn('id', $receiptIds)->delete();
            IntegrationModel::whereKey($id)->delete();
            PaymentProviderAccountModel::whereKey($accountId)->delete();
            PaymentProviderModel::whereKey($providerId)->delete();
            if (is_file($release)) unlink($release);
        }
    }
}
