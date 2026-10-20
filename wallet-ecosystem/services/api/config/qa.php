<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | QA Disaster Recovery Simulation
    |--------------------------------------------------------------------------
    | When false (default), DisasterRecoveryExecutor runs readiness assertions
    | only. Set to true ONLY during planned DR drills to trigger simulation steps.
    */
    'dr_simulation_enabled' => (bool) env('QA_DR_SIMULATION_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | QA Executor Thresholds
    |--------------------------------------------------------------------------
    | All thresholds are overridable via environment variables so CI environments
    | can tune without code changes.
    */
    'thresholds' => [
        // PerformanceExecutor (US-QA-014): fail if p95 latency exceeds this value (ms)
        'performance_p95_ms' => (int) env('QA_PERF_P95_MS', 500),

        // LoadExecutor (US-QA-015): number of concurrent fibers to dispatch
        'load_concurrency' => (int) env('QA_LOAD_CONCURRENCY', 50),

        // DisasterRecoveryExecutor (US-QA-016): readiness assertion bounds
        'dr_replica_lag_seconds'   => (int) env('QA_DR_REPLICA_LAG_SECONDS', 30),
        'dr_horizon_queue_depth'   => (int) env('QA_DR_HORIZON_QUEUE_DEPTH', 500),
        'dr_backup_max_age_hours'  => (int) env('QA_DR_BACKUP_MAX_AGE_HOURS', 24),
    ],
];
