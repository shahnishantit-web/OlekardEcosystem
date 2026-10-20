<?php

declare(strict_types=1);

namespace Tests\Support;

final class ConcurrencyTestHelper
{
    /**
     * Run $test closure in $processes parallel child processes.
     *
     * Asserts that total wall-clock time is less than sequential execution would take,
     * proving parallel execution.
     *
     * @param  Closure(): void $test  The closure must be expressible as a PHP snippet string via $testScript.
     * @param  string          $testScript  PHP code to run in each child process (must bootstrap Laravel if needed).
     * @return array<int, array{pid: int, exit_code: int, output: string, error: string}>
     */
    public static function runParallel(int $processes, string $testScript, float $sequentialEstimateSeconds = 1.0): array
    {
        if (! class_exists(\Symfony\Component\Process\Process::class)) {
            throw new \RuntimeException(
                'symfony/process is required for concurrency tests. Run: composer require --dev symfony/process'
            );
        }

        $startTime = microtime(true);
        $spawned   = [];
        $tmpFiles  = [];

        for ($i = 0; $i < $processes; $i++) {
            $tmpFile = tempnam(sys_get_temp_dir(), 'concurrency_test_');
            file_put_contents($tmpFile.'.php', $testScript);
            $tmpFiles[] = $tmpFile;

            $process = new \Symfony\Component\Process\Process(['php', $tmpFile.'.php']);
            $process->setTimeout(30);
            $process->start();

            $spawned[] = ['process' => $process, 'tmp' => $tmpFile, 'pid' => $process->getPid()];
        }

        $results = [];
        foreach ($spawned as $item) {
            /** @var \Symfony\Component\Process\Process $process */
            $process = $item['process'];
            $process->wait();

            $results[] = [
                'pid'       => $item['pid'],
                'exit_code' => $process->getExitCode(),
                'output'    => $process->getOutput(),
                'error'     => $process->getErrorOutput(),
            ];

            @unlink($item['tmp']);
            @unlink($item['tmp'].'.php');
        }

        $totalElapsed = microtime(true) - $startTime;

        // Assert parallel: total time must be less than 80% of sequential estimate
        $sequentialBound = $sequentialEstimateSeconds * $processes * 0.8;
        if ($totalElapsed >= $sequentialBound) {
            throw new \RuntimeException(sprintf(
                'Concurrency test failed: total elapsed %.3fs >= sequential bound %.3fs. '
                .'Processes may not have run concurrently.',
                $totalElapsed,
                $sequentialBound,
            ));
        }

        return $results;
    }
}
