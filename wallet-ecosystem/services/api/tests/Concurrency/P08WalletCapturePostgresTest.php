<?php

declare(strict_types=1);

use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Wallet\Application\Contracts\WalletHoldServiceContract;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$app->instance(AuditServiceInterface::class, new class implements AuditServiceInterface
{
    public function record(AuditEventData $event): void {}
});
$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$wallet = DB::connection('wallet');
$assert($wallet->getDriverName() === 'pgsql', 'P08 capture races require PostgreSQL.');
$barrierRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'p08-wallet-capture-'.bin2hex(random_bytes(8));
$assert(mkdir($barrierRoot, 0700), 'Could not create the race barrier directory.');
$runRace = static function (array $fixture, array $operations, float $startAt) use ($barrierRoot, $assert): array {
    $barrier = $barrierRoot.DIRECTORY_SEPARATOR.$fixture['label'];
    $workers = [];
    foreach ($operations as $operation) {
        $workerId = (string) Uuid::uuid7();
        $command = [PHP_BINARY, __DIR__.'/P08WalletCaptureRaceWorker.php', $operation, $fixture['hold'],
            $fixture['attempt'], $fixture['intent'], $fixture['organisation'], $fixture['owner'], '1250', 'BWP',
            (string) Uuid::uuid7(), $barrier, $workerId, $fixture['destination'], (string) $startAt];
        $pipes = [];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
        if (! is_resource($process)) {
            throw new RuntimeException('Could not start a PostgreSQL race worker.');
        }
        fclose($pipes[0]);
        $workers[] = ['process' => $process, 'pipes' => $pipes, 'worker' => $workerId];
    }
    $deadline = microtime(true) + 30;
    foreach ($workers as $worker) {
        while (! is_file($barrier.'.'.$worker['worker'].'.ready')) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('A worker did not reach the PostgreSQL barrier.');
            }
            usleep(10000);
        }
    }
    $pids = array_map(static fn (array $worker): string => trim((string) file_get_contents($barrier.'.'.$worker['worker'].'.ready')), $workers);
    $assert(count(array_unique($pids)) === count($workers), 'Racing Wallet operations must use separate PostgreSQL sessions.');
    file_put_contents($barrier.'.go', 'go', LOCK_EX);
    $results = [];
    foreach ($workers as $worker) {
        $stdout = stream_get_contents($worker['pipes'][1]);
        $stderr = stream_get_contents($worker['pipes'][2]);
        fclose($worker['pipes'][1]);
        fclose($worker['pipes'][2]);
        $exit = proc_close($worker['process']);
        if ($exit !== 0) {
            throw new RuntimeException('Race worker failed: '.trim((string) $stderr));
        }
        $results[] = json_decode((string) $stdout, true, 512, JSON_THROW_ON_ERROR);
    }

    return ['pids' => $pids, 'results' => $results];
};
$createFixture = static function (string $label, ?DateTimeInterface $expiresAt = null): array {
    $owner = (string) Uuid::uuid7();
    $walletId = (string) Uuid::uuid7();
    $pocket = (string) Uuid::uuid7();
    $attempt = (string) Uuid::uuid7();
    $intent = (string) Uuid::uuid7();
    $organisation = (string) Uuid::uuid7();
    $destination = 'p08-concurrency-test-destination:'.$intent;
    $correlation = (string) Uuid::uuid7();
    DB::connection('identity')->table('identity.users')->insert([
        'id' => $owner, 'status' => 'active', 'actor_type' => 'CUSTOMER', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::connection('wallet')->table('wallet.wallets')->insert([
        'id' => $walletId, 'wallet_reference' => 'P08-'.strtoupper(substr(str_replace('-', '', $walletId), 0, 18)),
        'owner_id' => $owner, 'status' => 'active', 'version' => 1, 'created_by' => $owner, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::connection('wallet')->table('wallet.currency_pockets')->insert([
        'id' => $pocket, 'wallet_id' => $walletId, 'currency_code' => 'BWP',
        'ledger_account_reference' => 'p08-concurrency-pocket:'.$pocket, 'status' => 'active',
        'created_by' => $owner, 'created_at' => now(), 'updated_at' => now(),
    ]);
    app(LedgerModuleContract::class)->postEntry([
        'idempotency_key' => 'p08-concurrency-fund:'.$intent, 'entry_type' => 'credit', 'currency_code' => 'BWP',
        'transaction_reference' => $walletId, 'correlation_id' => $correlation, 'actor_id' => $owner,
        'lines' => [
            ['ledger_account_reference' => 'p08-concurrency-funding:'.$intent, 'direction' => 'debit', 'amount' => 2500],
            ['ledger_account_reference' => 'p08-concurrency-pocket:'.$pocket, 'direction' => 'credit', 'amount' => 2500],
        ],
    ]);
    $hold = app(WalletHoldServiceContract::class)->place($walletId, 'BWP', 1250, $owner, $correlation,
        $attempt, expiresAt: $expiresAt === null ? null : DateTimeImmutable::createFromInterface($expiresAt), organisationId: $organisation);

    return ['label' => $label, 'owner' => $owner, 'wallet' => $walletId, 'pocket' => $pocket,
        'hold' => $hold->id, 'attempt' => $attempt, 'intent' => $intent,
        'organisation' => $organisation, 'destination' => $destination];
};

$outcomes = [];
try {
    foreach ([['capture', 'release'], ['capture', 'expire']] as $index => $operations) {
        $expiry = $index === 0 ? null : now()->addSeconds(4)->toDateTimeImmutable();
        $fixture = $createFixture('race-'.$index, $expiry);
        $raceStart = $index === 0 ? microtime(true) + 1.5 : (float) $expiry->format('U.u');
        $race = $runRace($fixture, $operations, $raceStart);
        $status = $wallet->table('wallet.wallet_holds')->where('id', $fixture['hold'])->value('status');
        $captureCount = DB::connection('platform')->table('ledger.journals')
            ->where('idempotency_key', 'payment:'.$fixture['intent'].':ack')->count();
        $recordCount = DB::connection('platform')->table('wallet.wallet_capture_records')->where('hold_id', $fixture['hold'])->count();
        $assert(in_array($status, $index === 0 ? ['captured', 'released'] : ['captured', 'expired'], true),
            'Each race must leave exactly one valid terminal hold state.');
        $assert($captureCount === ($status === 'captured' ? 1 : 0), 'Ledger debit must exist exactly when capture wins.');
        $assert($recordCount === ($status === 'captured' ? 1 : 0), 'Wallet capture evidence must match the winning state.');
        $outcomes[$index === 0 ? 'capture_vs_release' : 'capture_at_expiry'] = [
            'backend_pids' => $race['pids'], 'operations' => $race['results'], 'hold_status' => $status,
            'capture_journals' => $captureCount, 'capture_records' => $recordCount,
        ];
    }
    echo json_encode(['database' => config('database.connections.wallet.database'), 'driver' => $wallet->getDriverName(),
        'scenarios' => $outcomes], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    foreach (glob($barrierRoot.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
        @unlink($path);
    }
    @rmdir($barrierRoot);
}
