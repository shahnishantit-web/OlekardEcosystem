<?php

declare(strict_types=1);

namespace App\Modules\Events\Infrastructure\Http\Controllers;

use App\Modules\Events\Application\EventReportingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class EventReportController extends Controller
{
    public function __construct(private readonly EventReportingService $reports) {}

    public function show(Request $request, string $eventId): JsonResponse
    {
        return response()->json(['data' => $this->reports->forOrganiser($eventId, $this->actor($request))]);
    }

    public function export(Request $request, string $eventId)
    {
        $report = $this->reports->forOrganiser($eventId, $this->actor($request));
        $csv = $this->reports->csv($report);
        $filename = 'event-report-'.$eventId.'.csv';

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    private function actor(Request $request): string
    {
        $actor = (string) $request->attributes->get('identity.user_id', '');
        abort_unless(Str::isUuid($actor), 401, 'Authenticated actor context is missing.');

        return $actor;
    }
}
