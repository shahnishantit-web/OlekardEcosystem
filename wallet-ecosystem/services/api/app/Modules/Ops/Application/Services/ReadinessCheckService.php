<?php

declare(strict_types=1);

namespace App\Modules\Ops\Application\Services;

use Illuminate\Support\Facades\DB;
use Throwable;

final class ReadinessCheckService
{
    public function isReady(): bool
    {
        try {
            DB::connection('platform')->select('SELECT 1');
            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
