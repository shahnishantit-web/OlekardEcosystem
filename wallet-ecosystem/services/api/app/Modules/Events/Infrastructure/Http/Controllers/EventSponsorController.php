<?php

declare(strict_types=1);

namespace App\Modules\Events\Infrastructure\Http\Controllers;

use App\Modules\Events\Application\EventSponsorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class EventSponsorController extends Controller
{
    public function __construct(private readonly EventSponsorService $sponsors) {}

    public function index(Request $request, string $organisationId): JsonResponse
    {
        return response()->json(['data' => $this->sponsors->list($organisationId, $this->actor($request))]);
    }

    public function reportViewers(Request $request, string $organisationId): JsonResponse
    {
        return response()->json(['data' => $this->sponsors->reportViewers($organisationId, $this->actor($request))]);
    }

    public function store(Request $request, string $organisationId): JsonResponse
    {
        return response()->json(['data' => $this->sponsors->create($organisationId, $this->actor($request), $request->all())], 201);
    }

    public function link(Request $request, string $eventId): JsonResponse
    {
        $data = $request->validate(['sponsor_id' => ['required', 'uuid']]);
        $this->sponsors->link($eventId, $data['sponsor_id'], $this->actor($request));

        return response()->json(['data' => ['event_id' => $eventId, 'sponsor_id' => $data['sponsor_id'], 'status' => 'linked']]);
    }

    public function eventSponsors(Request $request, string $eventId): JsonResponse
    {
        return response()->json(['data' => $this->sponsors->listForEvent($eventId, $this->actor($request))]);
    }

    public function unlink(Request $request, string $eventId, string $sponsorId): JsonResponse
    {
        $this->sponsors->unlink($eventId, $sponsorId, $this->actor($request));

        return response()->json(['data' => ['event_id' => $eventId, 'sponsor_id' => $sponsorId, 'status' => 'unlinked']]);
    }

    public function report(Request $request, string $eventId): JsonResponse
    {
        return response()->json(['data' => $this->sponsors->report($eventId, $this->actor($request))]);
    }

    public function update(Request $request, string $organisationId, string $sponsorId): JsonResponse
    {
        return response()->json(['data' => $this->sponsors->update(
            $organisationId, $sponsorId, $this->actor($request), $request->all(),
        )]);
    }

    public function assignedReports(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->sponsors->reportsForActor($this->actor($request))]);
    }

    public function exportReport(Request $request, string $eventId)
    {
        $csv = $this->sponsors->reportCsv($eventId, $this->actor($request));

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="sponsor-event-report-'.$eventId.'.csv"',
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
