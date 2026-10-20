<?php

declare(strict_types=1);

namespace App\Modules\Agent\Infrastructure\Stubs;

use App\Modules\Agent\Domain\Contracts\AgentKycProviderContract;
use App\Modules\Agent\Domain\ValueObjects\AgentKycPayload;
use App\Modules\Agent\Domain\ValueObjects\AgentKycResult;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use Ramsey\Uuid\Uuid;

final class StubAgentKycProvider implements AgentKycProviderContract
{
    public function __construct(
        private readonly ConfigurationServiceInterface $config,
    ) {}

    public function verify(AgentKycPayload $payload): AgentKycResult
    {
        $stubResult = $this->config->getOrDefault('agent.kyc.stub_result', 'passed');
        $passed     = $stubResult === 'passed';

        return new AgentKycResult(
            passed:        $passed,
            reference:     'stub-' . (string) Uuid::uuid7(),
            failureReason: $passed ? null : 'STUB_KYC_FAILURE',
        );
    }

    public function providerName(): string
    {
        return 'stub';
    }
}
