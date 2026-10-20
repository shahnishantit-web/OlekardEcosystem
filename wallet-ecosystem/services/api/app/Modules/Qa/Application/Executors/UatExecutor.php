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
use Illuminate\Http\Request;

final class UatExecutor implements QaExecutorInterface
{
    use WritesTestResults;

    public function execute(TestRun $run): void
    {
        $cases = TestCase::active()->forCategory(QaCategory::Uat)->get();

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
            ScenarioType::HappyPath    => $this->uatAuthSc1(),
            ScenarioType::Validation   => $this->uatWalletBalanceSc1(),
            ScenarioType::Authorization => $this->uatKycSc1(),
            ScenarioType::Boundary     => $this->uatFundingSc1(),
            ScenarioType::Failure      => $this->uatTransferSc1(),
            ScenarioType::Idempotency  => $this->uatWithdrawalSc1(),
            ScenarioType::Audit        => $this->uatMerchantPaymentSc1(),
            ScenarioType::Regression   => $this->uatAdminDashboardSc1(),
        };
    }

    private function uatAuthSc1(): array
    {
        // E01 SC-1: Admin can log in and receive a token
        $status  = $this->call('POST', '/v1/auth/login', ['email' => 'admin@olekard.local', 'password' => 'Admin123!']);
        $outcome = in_array($status, [200, 201]) ? TestResultOutcome::Passed : TestResultOutcome::Failed;

        return [$outcome, $outcome === TestResultOutcome::Failed ? "E01 SC-1 login failed; status {$status}" : null, ['epic' => 'E01', 'scenario' => 'SC-1', 'status_code' => $status]];
    }

    private function uatWalletBalanceSc1(): array
    {
        // E06 SC-1: Wallet list endpoint is reachable (requires auth)
        $status  = $this->call('GET', '/v1/wallets', [], null);
        $outcome = ($status === 401) ? TestResultOutcome::Passed : TestResultOutcome::Failed;

        return [$outcome, $outcome === TestResultOutcome::Failed ? "E06 wallet endpoint not returning 401 without auth; status {$status}" : null, ['epic' => 'E06', 'scenario' => 'SC-1', 'status_code' => $status]];
    }

    private function uatKycSc1(): array
    {
        // E05 SC-1: KYC submission endpoint protected
        $status  = $this->call('POST', '/v1/kyc/applications', [], null);
        $outcome = ($status === 401) ? TestResultOutcome::Passed : TestResultOutcome::Failed;

        return [$outcome, $outcome === TestResultOutcome::Failed ? "E05 KYC endpoint not protected; status {$status}" : null, ['epic' => 'E05', 'scenario' => 'SC-1', 'status_code' => $status]];
    }

    private function uatFundingSc1(): array
    {
        // E08 SC-1: Funding initiation endpoint protected
        $status  = $this->call('POST', '/v1/funding/initiate', [], null);
        $outcome = ($status === 401) ? TestResultOutcome::Passed : TestResultOutcome::Failed;

        return [$outcome, $outcome === TestResultOutcome::Failed ? "E08 funding endpoint not protected; status {$status}" : null, ['epic' => 'E08', 'scenario' => 'SC-1', 'status_code' => $status]];
    }

    private function uatTransferSc1(): array
    {
        // E09 SC-1: Transfer endpoint protected
        $status  = $this->call('POST', '/v1/transfers', [], null);
        $outcome = ($status === 401) ? TestResultOutcome::Passed : TestResultOutcome::Failed;

        return [$outcome, $outcome === TestResultOutcome::Failed ? "E09 transfer endpoint not protected; status {$status}" : null, ['epic' => 'E09', 'scenario' => 'SC-1', 'status_code' => $status]];
    }

    private function uatWithdrawalSc1(): array
    {
        // E10 SC-1: Withdrawal endpoint protected
        $status  = $this->call('POST', '/v1/withdrawals', [], null);
        $outcome = ($status === 401) ? TestResultOutcome::Passed : TestResultOutcome::Failed;

        return [$outcome, $outcome === TestResultOutcome::Failed ? "E10 withdrawal endpoint not protected; status {$status}" : null, ['epic' => 'E10', 'scenario' => 'SC-1', 'status_code' => $status]];
    }

    private function uatMerchantPaymentSc1(): array
    {
        // E16 SC-1: QR payment endpoint protected
        $status  = $this->call('POST', '/v1/qr/payments', [], null);
        $outcome = ($status === 401) ? TestResultOutcome::Passed : TestResultOutcome::Failed;

        return [$outcome, $outcome === TestResultOutcome::Failed ? "E16 QR payment endpoint not protected; status {$status}" : null, ['epic' => 'E16', 'scenario' => 'SC-1', 'status_code' => $status]];
    }

    private function uatAdminDashboardSc1(): array
    {
        // E19 SC-1: Admin dashboard endpoint protected
        $status  = $this->call('GET', '/v1/admin/dashboard', [], null);
        $outcome = ($status === 401) ? TestResultOutcome::Passed : TestResultOutcome::Failed;

        return [$outcome, $outcome === TestResultOutcome::Failed ? "E19 admin dashboard not protected; status {$status}" : null, ['epic' => 'E19', 'scenario' => 'SC-1', 'status_code' => $status]];
    }

    private function call(string $method, string $uri, array $data, ?string $token = ''): int
    {
        $server = ['HTTP_ACCEPT' => 'application/json'];
        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = "Bearer {$token}";
        }
        if (!empty($data)) {
            $server['CONTENT_TYPE'] = 'application/json';
        }
        $req = Request::create($uri, $method, $data, [], [], $server, !empty($data) ? json_encode($data) : null);
        return app()->handle($req)->getStatusCode();
    }
}
