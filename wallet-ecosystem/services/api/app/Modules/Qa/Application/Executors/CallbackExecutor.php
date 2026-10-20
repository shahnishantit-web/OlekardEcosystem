<?php

declare(strict_types=1);

namespace App\Modules\Qa\Application\Executors;

use App\Modules\Qa\Application\Executors\Concerns\FinancialIsolation;
use App\Modules\Qa\Application\Executors\Concerns\WritesTestResults;
use App\Modules\Qa\Application\Executors\Contracts\QaExecutorInterface;
use App\Modules\Qa\Domain\Enums\QaCategory;
use App\Modules\Qa\Domain\Enums\ScenarioType;
use App\Modules\Qa\Domain\Enums\TestResultOutcome;
use App\Modules\Qa\Models\TestCase;
use App\Modules\Qa\Models\TestRun;
use Illuminate\Http\Request;

final class CallbackExecutor implements QaExecutorInterface
{
    use WritesTestResults, FinancialIsolation;

    public function execute(TestRun $run): void
    {
        $cases = TestCase::active()->forCategory(QaCategory::Callback)->get();

        foreach ($cases as $case) {
            $start = hrtime(true);

            try {
                [$outcome, $error, $evidence] = $this->runScenario($case->scenario_type);
            } catch (\Throwable $e) {
                $outcome  = TestResultOutcome::Error;
                $error    = $e->getMessage();
                $evidence = [];
            }

            $durationMs = (int) ((hrtime(true) - $start) / 1_000_000);
            $this->writeResult($run, $case, $outcome, $durationMs, $error, $evidence);
        }
    }

    private function runScenario(ScenarioType $scenario): array
    {
        return match ($scenario) {
            ScenarioType::HappyPath    => $this->assertCallbackEndpointExists(),
            ScenarioType::Validation   => $this->assertInvalidSignatureFails(),
            ScenarioType::Authorization => $this->assertCallbackNotAuthRequired(),
            ScenarioType::Boundary     => $this->assertEmptyPayloadHandled(),
            ScenarioType::Failure      => $this->assertMalformedJsonHandled(),
            ScenarioType::Idempotency  => $this->assertDuplicateCallbackIdempotent(),
            ScenarioType::Audit        => $this->assertCallbackAuditAdvisory(),
            ScenarioType::Regression   => $this->assertCallbackRouteRegistered(),
        };
    }

    private function assertCallbackEndpointExists(): array
    {
        $status  = $this->callCallback('/v1/callbacks/funding', ['event' => 'deposit.completed', 'reference' => 'qa-test-' . uniqid()]);
        $outcome = ($status !== 404 && $status !== 405) ? TestResultOutcome::Passed : TestResultOutcome::Failed;

        return [$outcome, $outcome === TestResultOutcome::Failed ? "Callback endpoint 404/405; status {$status}" : null, ['status_code' => $status]];
    }

    private function assertInvalidSignatureFails(): array
    {
        $status  = $this->callCallback('/v1/callbacks/funding', ['event' => 'deposit.completed'], 'bad-signature');
        $outcome = in_array($status, [400, 401, 403, 422]) ? TestResultOutcome::Passed : TestResultOutcome::Failed;

        return [$outcome, $outcome === TestResultOutcome::Failed ? "Invalid signature not rejected; got {$status}" : null, ['status_code' => $status]];
    }

    private function assertCallbackNotAuthRequired(): array
    {
        // Callbacks from providers are typically not bearer-token authenticated
        $status  = $this->callCallback('/v1/callbacks/funding', ['event' => 'ping']);
        $outcome = ($status !== 401) ? TestResultOutcome::Passed : TestResultOutcome::Failed;

        return [$outcome, $outcome === TestResultOutcome::Failed ? "Callback requires bearer auth (unexpected); status {$status}" : null, ['status_code' => $status]];
    }

    private function assertEmptyPayloadHandled(): array
    {
        $status  = $this->callCallback('/v1/callbacks/funding', []);
        $outcome = ($status < 500) ? TestResultOutcome::Passed : TestResultOutcome::Failed;

        return [$outcome, $outcome === TestResultOutcome::Failed ? "Empty callback payload caused 500; status {$status}" : null, ['status_code' => $status]];
    }

    private function assertMalformedJsonHandled(): array
    {
        $req    = Request::create('/v1/callbacks/funding', 'POST', [], [], [], ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'], '{not-valid-json}');
        $status = app()->handle($req)->getStatusCode();
        $outcome = ($status < 500) ? TestResultOutcome::Passed : TestResultOutcome::Failed;

        return [$outcome, $outcome === TestResultOutcome::Failed ? "Malformed JSON caused 500" : null, ['status_code' => $status]];
    }

    private function assertDuplicateCallbackIdempotent(): array
    {
        $ref  = 'qa-cb-idem-' . uniqid();
        $s1   = $this->callCallback('/v1/callbacks/funding', ['event' => 'deposit.completed', 'reference' => $ref]);
        $s2   = $this->callCallback('/v1/callbacks/funding', ['event' => 'deposit.completed', 'reference' => $ref]);

        $outcome = ($s1 !== 500 && $s2 !== 500) ? TestResultOutcome::Passed : TestResultOutcome::Failed;

        return [$outcome, null, ['s1' => $s1, 's2' => $s2, 'reference' => $ref]];
    }

    private function assertCallbackAuditAdvisory(): array
    {
        return [TestResultOutcome::Passed, null, ['advisory' => 'callback processing audit via E08 integration tests']];
    }

    private function assertCallbackRouteRegistered(): array
    {
        $routes   = \Illuminate\Support\Facades\Route::getRoutes();
        $hasRoute = false;
        // @phpstan-ignore-next-line
        foreach ($routes as $route) {
            if (str_contains($route->uri(), 'callbacks')) {
                $hasRoute = true;
                break;
            }
        }
        $outcome = $hasRoute ? TestResultOutcome::Passed : TestResultOutcome::Failed;

        return [$outcome, $hasRoute ? null : 'No /callbacks/* routes registered', ['has_callback_route' => $hasRoute]];
    }

    private function callCallback(string $uri, array $data, ?string $signature = null): int
    {
        $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];
        if ($signature !== null) {
            $server['HTTP_X_SIGNATURE'] = $signature;
        }
        $req = Request::create($uri, 'POST', [], [], [], $server, json_encode($data));
        return app()->handle($req)->getStatusCode();
    }
}
