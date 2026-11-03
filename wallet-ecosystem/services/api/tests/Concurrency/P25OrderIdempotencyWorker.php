<?php

declare(strict_types=1);

use App\Modules\Events\Application\EventOrderService;
use App\Modules\Events\Domain\Contracts\EventPricingContract;
use App\Modules\Events\Domain\ValueObjects\EventPricingBreakdown;
use App\Modules\Events\Domain\ValueObjects\EventPricingRequest;
use Illuminate\Contracts\Console\Kernel;

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

[$script, $eventId, $purchaserId, $quoteId, $key, $barrier, $workerId] = $argv;
$path = $barrier.DIRECTORY_SEPARATOR.$workerId;
file_put_contents($path.'.ready', (string) getmypid(), LOCK_EX);
$deadline = microtime(true) + 30;
while (! is_file($path.'.go')) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Worker timed out at the concurrency barrier.');
    }
    usleep(5000);
}
$order = app(EventOrderService::class)->confirmAndCreateOrder($eventId, $purchaserId, $quoteId, $key);
echo json_encode(['order_id' => $order['order_id'] ?? $order['id'], 'pid' => getmypid()], JSON_THROW_ON_ERROR);
