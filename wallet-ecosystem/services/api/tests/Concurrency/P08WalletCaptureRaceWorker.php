<?php

declare(strict_types=1);

use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Wallet\Application\Contracts\WalletHoldServiceContract;
use App\Modules\Wallet\Domain\Contracts\WalletCaptureContract;
use App\Modules\Wallet\Domain\ValueObjects\WalletCaptureRequest;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$app->instance(AuditServiceInterface::class, new class implements AuditServiceInterface
{
    public function record(AuditEventData $event): void {}
});
[$script, $operation, $holdId, $attemptId, $intentId, $organisationId, $ownerId, $amount, $currency,
    $correlationId, $barrier, $workerId, $destination, $startAt] = $argv;
config(['wallet.capture.test_only_enabled' => true, 'wallet.capture.test_destination_accounts.'.$currency => $destination]);
$wallet = DB::connection('wallet');
$pid = (int) $wallet->selectOne('SELECT pg_backend_pid() AS pid')->pid;
file_put_contents($barrier.'.'.$workerId.'.ready', (string) $pid, LOCK_EX);
$deadline = microtime(true) + 30;
while (! is_file($barrier.'.go')) {
    if (microtime(true) >= $deadline) {
        exit(2);
    }
    usleep(10000);
}
while (microtime(true) < (float) $startAt) {
    usleep(1000);
}

try {
    if ($operation === 'capture') {
        $evidence = app(WalletCaptureContract::class)->captureForPayment(new WalletCaptureRequest(
            $holdId, $attemptId, $intentId, $organisationId, $ownerId, (int) $amount, $currency, $correlationId,
        ));
        echo json_encode(['result' => 'captured', 'journal_id' => $evidence->ledgerJournalId, 'pid' => $pid], JSON_THROW_ON_ERROR);
    } elseif ($operation === 'release') {
        app(WalletHoldServiceContract::class)->release($holdId, $correlationId);
        echo json_encode(['result' => 'released', 'pid' => $pid], JSON_THROW_ON_ERROR);
    } elseif ($operation === 'expire') {
        Artisan::call('wallet:expire-holds');
        echo json_encode(['result' => 'expiry_checked', 'pid' => $pid], JSON_THROW_ON_ERROR);
    } else {
        throw new RuntimeException('Unknown race operation.');
    }
} catch (Throwable $exception) {
    echo json_encode(['result' => 'denied', 'type' => $exception::class, 'pid' => $pid], JSON_THROW_ON_ERROR);
}
