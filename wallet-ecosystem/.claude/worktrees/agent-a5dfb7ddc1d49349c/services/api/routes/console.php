<?php

use App\Modules\Profile\Infrastructure\Jobs\ExpiredContactChallengeCleanupJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// T122: nullify pending email/mobile fields whose OTP challenge has expired (E01 expires_at)
Schedule::job(ExpiredContactChallengeCleanupJob::class)->hourly();
