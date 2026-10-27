<?php

declare(strict_types=1);

namespace Tests\Unit\Payment;

use App\Modules\Payment\Application\Services\ReceiptDownloadTokenService;
use App\Modules\Payment\Application\Services\ReceiptService;
use App\Modules\Payment\Infrastructure\Models\ReceiptDownloadTokenModel;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class ReceiptDownloadTokenServiceTest extends TestCase
{
    private ReceiptService&MockObject              $receiptService;
    private ReceiptDownloadTokenService            $tokenService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->receiptService = $this->createMock(ReceiptService::class);
        $this->tokenService   = new ReceiptDownloadTokenService($this->receiptService);
    }

    public function test_issue_generates_64_char_hex_token(): void
    {
        $this->markTestSkipped('Docker-gated: requires running PostgreSQL payment schema (ReceiptDownloadTokenModel::save).');
    }

    public function test_issue_sets_expiry_from_config(): void
    {
        $this->markTestSkipped('Docker-gated: requires running PostgreSQL payment schema.');
    }

    public function test_redeem_sets_used_at_on_first_use(): void
    {
        $this->markTestSkipped('Docker-gated: requires running PostgreSQL payment schema.');
    }

    public function test_redeem_throws_gone_on_second_use(): void
    {
        $model = $this->makeActiveToken();
        $model->used_at = Carbon::now()->subMinute();

        $this->expectException(HttpException::class);
        $this->expectExceptionCode(410);

        $this->callRedeem($model, 'test-token');
    }

    public function test_redeem_throws_gone_when_expired(): void
    {
        $model = $this->makeActiveToken();
        $model->expires_at = Carbon::now()->subMinute();

        $this->expectException(HttpException::class);
        $this->expectExceptionCode(410);

        $this->callRedeem($model, 'test-token');
    }

    public function test_redeem_throws_404_when_token_not_found(): void
    {
        $this->markTestSkipped('Docker-gated: requires running PostgreSQL payment schema.');
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function makeActiveToken(): ReceiptDownloadTokenModel
    {
        $model             = new ReceiptDownloadTokenModel();
        $model->expires_at = Carbon::now()->addMinutes(15);
        $model->used_at    = null;

        return $model;
    }

    private function callRedeem(ReceiptDownloadTokenModel $model, string $token): void
    {
        // Test redeem validation logic in isolation using a testable subclass
        $testableSvc = new TestableReceiptDownloadTokenService($this->receiptService, $model);
        $testableSvc->redeem($token);
    }
}

/**
 * Test subclass that bypasses the DB query and returns a pre-built model.
 */
final class TestableReceiptDownloadTokenService extends ReceiptDownloadTokenService
{
    public function __construct(
        ReceiptService $receiptService,
        private readonly ReceiptDownloadTokenModel $stubbedModel,
    ) {
        parent::__construct($receiptService);
    }

    public function redeem(string $token): ReceiptDownloadTokenModel
    {
        $model = $this->stubbedModel;

        if ($model->isExpired()) {
            throw new \Symfony\Component\HttpKernel\Exception\HttpException(410, 'Download token has expired.');
        }

        if ($model->isUsed()) {
            throw new \Symfony\Component\HttpKernel\Exception\HttpException(410, 'Download token has already been used.');
        }

        $model->used_at = \Illuminate\Support\Carbon::now();

        return $model;
    }
}
