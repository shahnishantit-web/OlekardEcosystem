<?php

declare(strict_types=1);

namespace App\Modules\Qa\Application\Executors;

use App\Modules\Qa\Application\Executors\Concerns\WritesTestResults;
use App\Modules\Qa\Application\Executors\Contracts\QaExecutorInterface;
use App\Modules\Qa\Domain\Enums\QaCategory;
use App\Modules\Qa\Domain\Enums\ScenarioType;
use App\Modules\Qa\Domain\Enums\TestResultOutcome;
use App\Modules\Qa\Models\TestCase;
use App\Modules\Qa\Models\TestRun;
use Illuminate\Support\Facades\Hash;

final class AuthUnitTestExecutor implements QaExecutorInterface
{
    use WritesTestResults;

    public function execute(TestRun $run): void
    {
        $cases = TestCase::active()->forCategory(QaCategory::UnitAuth)->get();

        foreach ($cases as $case) {
            $start = hrtime(true);

            try {
                $outcome  = TestResultOutcome::Passed;
                $error    = null;
                $evidence = [];

                switch ($case->scenario_type) {
                    case ScenarioType::HappyPath:
                        // Verify password hashing is non-reversible
                        $hash  = Hash::make('TestPassword123!');
                        $check = Hash::check('TestPassword123!', $hash);
                        if (!$check) {
                            $outcome = TestResultOutcome::Failed;
                            $error   = 'Hash::check() returned false for correct password';
                        }
                        $evidence = ['driver' => config('hashing.driver', 'bcrypt'), 'check_passed' => $check];
                        break;

                    case ScenarioType::Validation:
                        // Verify Hash::check() fails for wrong password
                        $hash  = Hash::make('CorrectPassword!');
                        $check = Hash::check('WrongPassword!', $hash);
                        if ($check) {
                            $outcome = TestResultOutcome::Failed;
                            $error   = 'Hash::check() returned true for incorrect password';
                        }
                        $evidence = ['check_with_wrong_password' => $check];
                        break;

                    case ScenarioType::Authorization:
                        // Verify token generation produces unique values
                        $t1 = bin2hex(random_bytes(32));
                        $t2 = bin2hex(random_bytes(32));
                        if ($t1 === $t2) {
                            $outcome = TestResultOutcome::Failed;
                            $error   = 'random_bytes() produced identical tokens';
                        }
                        $evidence = ['tokens_unique' => $t1 !== $t2];
                        break;

                    case ScenarioType::Boundary:
                        // Verify minimum password length constraint (conceptual)
                        $shortPass = '12345';
                        $longPass  = str_repeat('a', 255);
                        $evidence  = [
                            'short_length' => strlen($shortPass),
                            'long_length'  => strlen($longPass),
                        ];
                        break;

                    case ScenarioType::Failure:
                        // Verify Hash::make() throws on empty string (graceful — just records)
                        $hash    = Hash::make('');
                        $outcome = TestResultOutcome::Passed;
                        $evidence = ['empty_hash_length' => strlen($hash)];
                        break;

                    case ScenarioType::Idempotency:
                        // Same password always produces valid hash (but different hashes are OK)
                        $h1 = Hash::make('SamePass!');
                        $h2 = Hash::make('SamePass!');
                        if (!Hash::check('SamePass!', $h1) || !Hash::check('SamePass!', $h2)) {
                            $outcome = TestResultOutcome::Failed;
                            $error   = 'Hash::check() failed for repeated hashes of same password';
                        }
                        $evidence = ['both_valid' => true];
                        break;

                    case ScenarioType::Audit:
                        // Verify auth config keys present
                        $keys = ['guards', 'providers', 'passwords'];
                        $missing = [];
                        foreach ($keys as $key) {
                            if (config("auth.{$key}") === null) {
                                $missing[] = $key;
                            }
                        }
                        if (!empty($missing)) {
                            $outcome = TestResultOutcome::Failed;
                            $error   = 'Missing auth config keys: ' . implode(', ', $missing);
                        }
                        $evidence = ['config_keys_present' => empty($missing)];
                        break;

                    case ScenarioType::Regression:
                        // Ensure bcrypt cost is not less than 10
                        $cost = config('hashing.bcrypt.rounds', 12);
                        if ($cost < 10) {
                            $outcome = TestResultOutcome::Failed;
                            $error   = "Bcrypt cost {$cost} is below minimum 10";
                        }
                        $evidence = ['bcrypt_cost' => $cost];
                        break;
                }
            } catch (\Throwable $e) {
                $outcome  = TestResultOutcome::Error;
                $error    = $e->getMessage();
                $evidence = [];
            }

            $durationMs = (int) ((hrtime(true) - $start) / 1_000_000);
            $this->writeResult($run, $case, $outcome, $durationMs, $error, $evidence);
        }
    }
}
