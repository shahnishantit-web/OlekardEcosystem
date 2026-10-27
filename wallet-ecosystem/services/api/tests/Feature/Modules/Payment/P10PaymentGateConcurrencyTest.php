<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Payment;

use Illuminate\Support\Facades\DB;
use Tests\Support\P10PaymentFixture;
use Tests\TestCase;

final class P10PaymentGateConcurrencyTest extends TestCase
{
    use P10PaymentFixture;

    public function test_two_processes_with_different_keys_execute_one_operation(): void
    {
        $this->exercise(crash: false);
    }

    public function test_hard_crash_recovers_original_operation_without_second_initiation(): void
    {
        $this->exercise(crash: true);
    }

    private function worker(string $mode, string $intent, string $provider, string $directory, string $key): array
    {
        $options = PHP_OS_FAMILY === 'Windows' ? ['-d', 'extension=php_pdo_pgsql.dll', '-d', 'extension=php_pgsql.dll'] : [];
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-d', 'xdebug.mode=off', ...$options, base_path('tests/Support/P10PaymentGateWorker.php'),
            $mode, $intent, $provider, $directory, $key], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
        $this->assertIsResource($process);
        fclose($pipes[0]);
        return [$process, $pipes];
    }

    private function finish(array $worker, int $expectedExit = 0): string
    {
        [$process, $pipes] = $worker;
        $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $this->assertSame($expectedExit, proc_close($process), $error);
        return $output;
    }

    private function exercise(bool $crash): void
    {
        [$intent, $provider, $account] = $this->paymentFixture();
        $directory = storage_path('framework/p10-gate-'.$intent->id);
        mkdir($directory);
        $workers = [];
        try {
            $workers[] = $this->worker($crash ? 'crash' : 'submit', $intent->id, $provider->code, $directory, 'a');
            if (! $crash) $workers[] = $this->worker('submit', $intent->id, $provider->code, $directory, 'b');
            $deadline = microtime(true) + 15;
            while (count(glob($directory.'/ready-*')) < count($workers) && microtime(true) < $deadline) usleep(10000);
            $this->assertCount(count($workers), glob($directory.'/ready-*'));
            touch($directory.'/release');
            $outputs = [];
            foreach ($workers as $worker) $outputs[] = $this->finish($worker, $crash ? 23 : 0);
            $effects = file($directory.'/effects.jsonl', FILE_IGNORE_NEW_LINES);
            $this->assertCount(1, $effects);
            $attempt = DB::connection('payment')->table('payment.payment_attempts')->where('payment_intent_id', $intent->id)->sole();
            $this->assertSame(json_decode($effects[0], true)['operation'], $attempt->operation_id);
            if ($crash) {
                $this->assertSame('submitting', $attempt->status);
                for ($i = 0; $i < 2; $i++) {
                    $this->finish($this->worker('recover', $intent->id, $provider->code, $directory, 'recovery-'.$i));
                }
                $this->assertCount(1, file($directory.'/queries.jsonl', FILE_IGNORE_NEW_LINES));
                $this->assertSame($attempt->operation_id, trim(file_get_contents($directory.'/queries.jsonl')));
                $intent->refresh();
                $this->assertSame('succeeded', $intent->status->value);
                $this->assertSame('pending_ledger', $intent->accounting_status->value); // External accounting is not invented.
            } else {
                $this->assertSame(1, count(array_filter($outputs, static fn ($out) => str_contains($out, 'RESULT=BLOCKED'))));
            }
            $this->assertSame(1, DB::connection('payment')->table('payment.payment_attempts')->where('payment_intent_id', $intent->id)->count());
            $this->assertCount(1, file($directory.'/effects.jsonl', FILE_IGNORE_NEW_LINES));
            $this->assertSame(0, DB::connection('platform')->table('ledger.journals')->where('transaction_reference', $intent->id)->count());
        } finally {
            foreach ($workers as [$process, $pipes]) {
                if (is_resource($process)) { proc_terminate($process); proc_close($process); }
                foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
            }
            // Exact test-only IDs; no ledger or wallet record is ever deleted.
            DB::connection('platform')->table('platform.outbox_events')->where('aggregate_id', $intent->id)->delete();
            DB::connection('payment')->table('payment.reconciliation_exceptions')->where('payment_intent_id', $intent->id)->delete();
            DB::connection('payment')->table('payment.payment_attempts')->where('payment_intent_id', $intent->id)->delete();
            $intent->delete(); $account->delete(); $provider->delete();
            foreach (glob($directory.'/*') as $file) unlink($file);
            rmdir($directory);
        }
    }
}
