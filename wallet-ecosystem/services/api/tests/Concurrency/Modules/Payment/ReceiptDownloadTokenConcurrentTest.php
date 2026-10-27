<?php

declare(strict_types=1);

namespace Tests\Concurrency\Modules\Payment;

use Tests\TestCase;

final class ReceiptDownloadTokenConcurrentTest extends TestCase
{
    public function test_concurrent_token_redemption_only_one_succeeds(): void
    {
        $this->markTestSkipped(
            'Docker-gated: requires running PostgreSQL with payment schema. ' .
            'Validate that the UNIQUE constraint on receipt_download_tokens.used_at ' .
            'combined with the UPDATE+SELECT pattern ensures only one concurrent ' .
            'redemption succeeds; the second receives HTTP 410 Gone.'
        );
    }

    public function test_concurrent_export_token_download_only_first_succeeds(): void
    {
        $this->markTestSkipped(
            'Docker-gated: requires running PostgreSQL with payment schema. ' .
            'Validate that concurrent requests to GET /exports/download/{token} ' .
            'allow only the first to succeed; the second receives HTTP 410 Gone ' .
            'because downloaded_at is set atomically.'
        );
    }
}
