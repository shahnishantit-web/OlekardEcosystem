<?php

declare(strict_types=1);

// Explicit development-only test adapter. No network, wallet mutation or bank API.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
putenv('APP_ENV=testing');
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
\App\Modules\Payment\Infrastructure\Adapters\DevelopmentPaymentGuard::check();
config(['queue.default' => 'null', 'cache.default' => 'array']);
$app->instance(\App\Modules\Platform\Domain\Contracts\AuditServiceInterface::class, new class implements \App\Modules\Platform\Domain\Contracts\AuditServiceInterface {
    public function record(\App\Modules\Platform\Domain\ValueObjects\AuditEventData $event): void {}
});
[$script, $mode, $intentId, $provider, $directory, $key] = $argv;
$adapter = new class ($directory, $mode) implements \App\Modules\Payment\Domain\Contracts\InitiatesPayment, \App\Modules\Payment\Domain\Contracts\QueriesPayment {
    public function __construct(private string $directory, private string $mode) {}
    public function initiatePayment(\App\Modules\Payment\Domain\ValueObjects\PaymentInitiateRequest $request): \App\Modules\Payment\Domain\ValueObjects\PaymentAttemptResult
    {
        // Verify persist-before-execute from an independent PostgreSQL session.
        $connection = \Illuminate\Support\Facades\DB::connection('platform');
        if (! $connection->table('payment.payment_attempts')->where('id', $request->paymentAttemptId)->where('status', 'submitting')->exists()) {
            throw new \RuntimeException('attempt_not_committed_before_execution');
        }
        file_put_contents($this->directory.'/effects.jsonl', json_encode(['operation' => $request->operationId]).PHP_EOL, FILE_APPEND|LOCK_EX);
        if ($this->mode === 'crash') exit(23); // Hard process loss after test-only external acceptance.
        usleep(200000);
        return new \App\Modules\Payment\Domain\ValueObjects\PaymentAttemptResult(
            status: \App\Modules\Payment\Domain\Enums\PaymentAttemptStatus::Processing,
            providerReferenceId: $request->operationId,
        );
    }
    public function queryPaymentStatus(string $providerReferenceId): \App\Modules\Payment\Domain\ValueObjects\PaymentStatusResult
    {
        file_put_contents($this->directory.'/queries.jsonl', $providerReferenceId.PHP_EOL, FILE_APPEND|LOCK_EX);
        return new \App\Modules\Payment\Domain\ValueObjects\PaymentStatusResult(
            \App\Modules\Payment\Domain\Enums\PaymentAttemptStatus::Succeeded, $providerReferenceId,
        );
    }
};
$app->instance('payment.adapter.'.$provider, $adapter);
try {
    $intent = \App\Modules\Payment\Infrastructure\Models\PaymentIntentModel::findOrFail($intentId);
    if ($mode === 'recover') {
        $attempt = \App\Modules\Payment\Infrastructure\Models\PaymentAttemptModel::where('payment_intent_id', $intentId)->firstOrFail();
        $app->call([new \App\Modules\Payment\Application\Jobs\PaymentAttemptSingleRecoveryJob($attempt->id), 'handle']);
    } else {
        touch($directory.'/ready-'.$key);
        $deadline = microtime(true) + 15;
        while (! is_file($directory.'/release') && microtime(true) < $deadline) usleep(10000);
        if (! is_file($directory.'/release')) throw new \RuntimeException('barrier_timeout');
        $app->make(\App\Modules\Payment\Application\PaymentOrchestrationService::class)->initiateAttempt([
            'payment_intent_id' => $intentId, 'provider_code' => $provider,
            'payer_actor_id' => $intent->payer_actor_id, 'idempotency_key' => $key, 'correlation_id' => $intent->correlation_id,
        ]);
    }
    echo 'RESULT=OK'.PHP_EOL;
} catch (\App\Modules\Payment\Domain\Exceptions\DuplicateAttemptException) {
    echo 'RESULT=BLOCKED'.PHP_EOL;
} catch (\Throwable $error) {
    fwrite(STDERR, $error::class.': '.$error->getMessage().PHP_EOL);
    exit(1);
}
