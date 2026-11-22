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

    public function report(Request $request, string $eventId): JsonResponse
    {
        return response()->json(['data' => $this->sponsors->report($eventId, $this->actor($request))]);
    }

    private function actor(Request $request): string
    {
        $actor = (string) $request->attributes->get('identity.user_id', '');
        abort_unless(Str::isUuid($actor), 401, 'Authenticated actor context is missing.');
        return $actor;
    }
}
