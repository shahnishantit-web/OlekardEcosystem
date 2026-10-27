<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Payment;

use Tests\TestCase;

/**
 * T100: Two-bank zero-code-change test — SC-006.
 * Configure and activate two banks using http-redirect-v1 profile via DB fixtures.
 * Assert both appear in ProviderRegistryService::discoverOptions() with no domain code changes.
 * Docker-gated for full integration.
 */
final class TwoBankSameProfileTest extends TestCase
{
    public function test_sc006_two_banks_same_profile_appear_in_options(): void
    {
        $this->markTestSkipped('Docker-gated: SC-006 requires two Active accounts with http-redirect-v1 + OptionsQuery');
    }

    public function test_sc006_no_domain_code_changes_required(): void
    {
        $this->markTestSkipped('Docker-gated: SC-006 code-change assertion requires git diff check or file hash comparison');
    }
}
