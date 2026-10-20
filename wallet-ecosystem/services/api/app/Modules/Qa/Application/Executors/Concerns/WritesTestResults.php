<?php

declare(strict_types=1);

namespace App\Modules\Qa\Application\Executors\Concerns;

use App\Modules\Qa\Domain\Enums\TestResultOutcome;
use App\Modules\Qa\Models\TestCase;
use App\Modules\Qa\Models\TestResult;
use App\Modules\Qa\Models\TestRun;

trait WritesTestResults
{
    protected function writeResult(
        TestRun           $run,
        TestCase          $case,
        TestResultOutcome $outcome,
        int               $durationMs,
        ?string           $errorMessage,
        array             $evidence = [],
    ): TestResult {
        $result                = new TestResult();
        $result->test_run_id   = $run->id;
        $result->test_case_id  = $case->id;
        $result->outcome       = $outcome;
        $result->duration_ms   = $durationMs;
        $result->error_message = $errorMessage;
        $result->evidence      = empty($evidence) ? null : $evidence;
        $result->save();

        return $result;
    }

    protected function writeSkipped(TestRun $run, TestCase $case, string $reason): TestResult
    {
        return $this->writeResult($run, $case, TestResultOutcome::Skipped, 0, $reason);
    }

    protected function writeError(TestRun $run, TestCase $case, \Throwable $e, int $durationMs = 0): TestResult
    {
        return $this->writeResult($run, $case, TestResultOutcome::Error, $durationMs, $e->getMessage());
    }
}
