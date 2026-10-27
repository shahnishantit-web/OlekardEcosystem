<?php

declare(strict_types=1);

// Test subprocess only. No financial attempt exists and no external adapter executes.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
$_ENV['AUDIT_HMAC_KEY'] = 'test-hmac-key-for-phpunit-only';
$_ENV['P10_RACE_TEST_SECRET'] = 'p10-race-test-only';
putenv('APP_ENV=testing');
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['queue.default' => 'null', 'cache.default' => 'array', 'cache.stores.redis' => ['driver' => 'array']]);
try {
    // Both workers wait for the parent release file, then race on the same event.
    $deadline = microtime(true) + 10;
    while (! is_file($argv[2]) && microtime(true) < $deadline) usleep(10000);
    if (! is_file($argv[2])) throw new \RuntimeException('race_start_timeout');
    $body = json_encode(['event_id' => 'race-event', 'transaction_ref' => $argv[3],
        'merchant_account' => 'race-merchant', 'amount' => 100, 'currency' => 'BWP', 'status' => 'success'], JSON_THROW_ON_ERROR);
    $receipt = app(\App\Modules\Integration\Application\Services\WebhookApplicationService::class)
        ->receive($argv[1], $body, hash_hmac('sha256', $body, 'p10-race-test-only'), [], '127.0.0.1');
    echo 'RECEIPT='.$receipt->id.PHP_EOL;
} catch (\Throwable $error) {
    fwrite(STDERR, 'ERROR='.$error::class.PHP_EOL);
    exit(1);
}
