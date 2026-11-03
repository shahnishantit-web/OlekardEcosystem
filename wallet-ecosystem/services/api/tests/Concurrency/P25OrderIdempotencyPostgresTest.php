<?php

declare(strict_types=1);

use App\Modules\Events\Application\EventOrderService;
use App\Modules\Events\Domain\Contracts\EventPricingContract;
use App\Modules\Events\Domain\ValueObjects\EventPricingBreakdown;
use App\Modules\Events\Domain\ValueObjects\EventPricingRequest;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
putenv('APP_ENV=testing');
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$app->instance(EventPricingContract::class, new class implements EventPricingContract
{
    public function quote(EventPricingRequest $request): EventPricingBreakdown
    {
        $subtotal = $request->subtotalMinor();

        return new EventPricingBreakdown($subtotal, 30, 200, $subtotal + 230, $request->currencyCode,
            $request->currencyPrecision, 'test-only-seller', 'test-fees-v1', 'test-tax-v1',
            [['code' => 'test-fee', 'amount_minor' => 30, 'policy_version' => 'test-fees-v1']],
            [['code' => 'test-tax', 'amount_minor' => 200, 'policy_version' => 'test-tax-v1']],
            now()->addMinutes(10)->toImmutable(), 'test-only-policy-evidence', false, false, true);
    }
});
$events = DB::connection('community_services');
$platform = DB::connection('platform');
$assert = static function (bool $ok, string $message): void {
    if (! $ok) {
        throw new RuntimeException($message);
    }
};
$dbIdentity = $events->selectOne('SELECT current_database() AS name, pg_backend_pid() AS pid');
$assert($events->getDriverName() === 'pgsql' && $dbIdentity->name === 'wallet_ecosystem_bootstrap_20261028',
    'Order idempotency race must use the explicitly isolated P08 PostgreSQL database.');
$currency = $platform->table('multi_currency.currencies')->where('is_active', true)->orderBy('code')->first(['code', 'decimal_places', 'updated_at']);
$assert($currency !== null, 'No active test currency exists.');
$eventId = (string) Uuid::uuid7();
$categoryId = (string) Uuid::uuid7();
$holdId = (string) Uuid::uuid7();
$organisationId = (string) Uuid::uuid7();
$purchaserId = (string) Uuid::uuid7();
$assignmentId = (string) Uuid::uuid7();
$now = now();
$events->table('community_services.event_drafts')->insert([
    'id' => $eventId, 'organisation_id' => $organisationId, 'created_by' => $purchaserId,
    'title' => 'P25 order idempotency race', 'event_format' => 'physical', 'venue_country_code' => 'BW',
    'organiser_name' => 'test-only', 'sponsors' => json_encode([], JSON_THROW_ON_ERROR), 'capacity' => 1,
    'currency_code' => $currency->code, 'currency_precision_snapshot' => $currency->decimal_places,
    'currency_definition_updated_at' => $currency->updated_at, 'currency_assignment_id' => $assignmentId,
    'timezone' => 'Africa/Gaborone', 'starts_at' => $now->copy()->addDays(2), 'ends_at' => $now->copy()->addDays(2)->addHours(2),
    'sales_start_at' => $now->copy()->subHour(), 'sales_end_at' => $now->copy()->addDay(),
    'cancellation_policy' => 'test-only', 'transfer_policy' => 'test-only', 'entry_policy' => 'test-only',
    'version' => 1, 'max_tickets_per_order' => 1, 'hold_duration_seconds' => 3600,
    'workflow_state' => 'published', 'sales_state' => 'active', 'published_version' => 1, 'created_at' => $now, 'updated_at' => $now,
]);
$events->table('community_services.event_currency_assignments')->insert([
    'id' => $assignmentId, 'event_id' => $eventId, 'organisation_id' => $organisationId,
    'currency_code' => $currency->code, 'precision_snapshot' => $currency->decimal_places,
    'currency_definition_updated_at' => $currency->updated_at, 'assigned_by_actor_id' => $purchaserId,
    'approval_reference' => 'test-only', 'policy_version' => 'test-only', 'assigned_at' => $now, 'created_at' => $now, 'updated_at' => $now,
]);
$events->table('community_services.event_draft_categories')->insert([
    'id' => $categoryId, 'event_id' => $eventId, 'name' => 'General', 'capacity' => 1,
    'price_minor' => 1000, 'issued_quantity' => 0, 'created_at' => $now, 'updated_at' => $now,
]);
$events->table('community_services.event_inventory_holds')->insert([
    'id' => $holdId, 'event_id' => $eventId, 'category_id' => $categoryId, 'category_name' => 'General',
    'organisation_id' => $organisationId, 'customer_actor_id' => $purchaserId, 'quantity' => 1,
    'idempotency_key' => 'p25-race-hold', 'request_hash' => hash('sha256', 'p25-race'), 'status' => 'held',
    'expires_at' => $now->copy()->addMinutes(20), 'created_at' => $now, 'updated_at' => $now,
]);
$quote = app(EventOrderService::class)->createQuote($eventId, $purchaserId, [$holdId], 'p25-race-quote');
$barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'p25-order-race-'.bin2hex(random_bytes(8));
$assert(mkdir($barrier, 0700), 'Could not create isolated concurrency barrier directory.');
$processes = [];
try {
    foreach ([1, 2] as $index) {
        $workerId = (string) Uuid::uuid7();
        $command = [PHP_BINARY, __DIR__.'/P25OrderIdempotencyWorker.php', $eventId, $purchaserId, $quote['quote_id'], 'p25-same-order-key', $barrier, $workerId];
        $pipes = [];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
        if (! is_resource($process)) {
            throw new RuntimeException('Could not start separate order worker.');
        }
        fclose($pipes[0]);
        $processes[] = ['process' => $process, 'pipes' => $pipes, 'id' => $workerId];
    }
    $deadline = microtime(true) + 30;
    foreach ($processes as $worker) {
        while (! is_file($barrier.DIRECTORY_SEPARATOR.$worker['id'].'.ready')) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Separate order worker did not reach barrier.');
            }
            usleep(5000);
        }
    }
    foreach ($processes as $worker) {
        file_put_contents($barrier.DIRECTORY_SEPARATOR.$worker['id'].'.go', 'go', LOCK_EX);
    }
    $results = [];
    foreach ($processes as $worker) {
        $stdout = stream_get_contents($worker['pipes'][1]);
        $stderr = stream_get_contents($worker['pipes'][2]);
        fclose($worker['pipes'][1]);
        fclose($worker['pipes'][2]);
        $exit = proc_close($worker['process']);
        if ($exit !== 0) {
            throw new RuntimeException('Concurrent order worker failed: '.$stderr.' '.$stdout);
        }
        $results[] = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    }
    $assert($results[0]['order_id'] === $results[1]['order_id'], 'Concurrent replay did not return one durable order.');
    $assert($events->table('community_services.event_orders')->where('event_id', $eventId)->count() === 1,
        'Concurrent duplicate order request created multiple orders.');
    $assert($events->table('community_services.event_drafts')->where('id', $eventId)->value('currency_lock_reason') === 'durable_order',
        'The concurrent first order did not lock the event currency.');
    echo json_encode(['status' => 'PASS', 'database' => $dbIdentity->name, 'worker_pids' => array_column($results, 'pid'),
        'order_id' => $results[0]['order_id'], 'orders' => 1, 'currency_lock' => 'durable_order'], JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    $orderIds = $events->table('community_services.event_orders')->where('event_id', $eventId)->pluck('id');
    if ($orderIds->isNotEmpty()) {
        $events->table('community_services.event_order_lines')->whereIn('order_id', $orderIds)->delete();
        $events->table('community_services.event_orders')->whereIn('id', $orderIds)->delete();
    }
    $events->table('community_services.event_order_quotes')->where('event_id', $eventId)->delete();
    $events->table('community_services.event_inventory_holds')->where('event_id', $eventId)->delete();
    $events->table('community_services.event_draft_categories')->where('event_id', $eventId)->delete();
    $events->table('community_services.event_currency_assignments')->where('event_id', $eventId)->delete();
    $events->table('community_services.event_drafts')->where('id', $eventId)->delete();
    foreach (glob($barrier.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($barrier);
}
