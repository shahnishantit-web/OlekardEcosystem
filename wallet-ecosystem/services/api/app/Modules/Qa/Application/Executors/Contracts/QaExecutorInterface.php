<?php

declare(strict_types=1);

namespace App\Modules\Qa\Application\Executors\Contracts;

use App\Modules\Qa\Models\TestRun;

interface QaExecutorInterface
{
    /**
     * Execute all test cases for this category against the given run.
     * MUST NOT throw — all errors are caught and written as TestResult with outcome `error`.
     */
    public function execute(TestRun $run): void;
}
