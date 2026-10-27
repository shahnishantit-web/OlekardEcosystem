<?php

declare(strict_types=1);

namespace Tests\Unit\Payment;

use App\Modules\Payment\Application\Services\PaymentExportService;
use Illuminate\Auth\Access\AuthorizationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class PaymentExportServiceTest extends TestCase
{
    public function test_request_requires_organisation_permission_for_org_scope(): void
    {
        $this->markTestSkipped('Docker-gated: requires running PostgreSQL payment schema.');
    }

    public function test_request_requires_platform_permission_for_platform_scope(): void
    {
        $this->markTestSkipped('Docker-gated: requires running PostgreSQL payment schema.');
    }

    public function test_redeem_token_throws_gone_when_expired(): void
    {
        $this->markTestSkipped('Docker-gated: requires running PostgreSQL payment schema (PaymentExportRequestModel::where).');
    }

    public function test_redeem_token_throws_gone_when_already_downloaded(): void
    {
        $this->markTestSkipped('Docker-gated: requires running PostgreSQL payment schema.');
    }

    public function test_status_throws_forbidden_when_requestor_is_not_owner(): void
    {
        $this->markTestSkipped('Docker-gated: requires running PostgreSQL payment schema.');
    }
}
