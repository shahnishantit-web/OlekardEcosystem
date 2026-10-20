<?php

declare(strict_types=1);

namespace App\Modules\Risk\Application\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

final class DeliverInvestigationEventsJob implements ShouldQueue
{
    use Dispatchable,Queueable;

    public function handle(): void
    {
        // The domain connection owns the durable outbox: hold and event commit together.
        $ids = DB::connection('risk')->table('risk.investigation_outbox')->whereNull('delivered_at')->orderBy('created_at')->limit(100)->pluck('id');
        foreach ($ids as $id) {
            DB::connection('risk')->transaction(function () use ($id): void {
                $row = DB::connection('risk')->table('risk.investigation_outbox')->where('id', $id)->lockForUpdate()->first();
                if (! $row || $row->delivered_at) {
                    return;
                }
                Event::dispatch($row->event_name, [json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR)]);
                DB::connection('risk')->table('risk.investigation_outbox')->where('id', $id)->update(['delivered_at' => now()]);
            });
        }
    }
}
