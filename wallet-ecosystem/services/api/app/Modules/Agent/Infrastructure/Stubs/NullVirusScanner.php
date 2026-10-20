<?php

declare(strict_types=1);

namespace App\Modules\Agent\Infrastructure\Stubs;

use App\Modules\Agent\Domain\Contracts\VirusScannerContract;
use App\Modules\Agent\Domain\ValueObjects\VirusScanResult;
use Illuminate\Http\UploadedFile;

final class NullVirusScanner implements VirusScannerContract
{
    public function scan(UploadedFile $file): VirusScanResult
    {
        // Support test override: AGENT_VIRUS_SCAN_STUB_RESULT=infected simulates virus detection
        $stubResult = env('AGENT_VIRUS_SCAN_STUB_RESULT', 'clean');

        if ($stubResult === 'infected') {
            return new VirusScanResult(clean: false, threatName: 'STUB_THREAT');
        }

        return new VirusScanResult(clean: true);
    }
}
