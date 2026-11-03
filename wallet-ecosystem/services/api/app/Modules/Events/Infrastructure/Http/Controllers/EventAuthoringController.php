<?php

declare(strict_types=1);

namespace App\Modules\Events\Infrastructure\Http\Controllers;

use App\Modules\Events\Application\EventDraftService;
use App\Modules\Events\Application\EventWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

/** Development authoring and review surface. It has no publish or sales endpoint. */
final class EventAuthoringController extends Controller
{
    public function __construct(
        private readonly EventDraftService $drafts,
        private readonly EventWorkflowService $workflow,
    ) {}

    public function store(Request $request, string $organisationId): JsonResponse
    {
        return response()->json(['data' => $this->drafts->create(
            $organisationId, $this->actor($request), $request->all(),
        )], 201);
    }

    public function show(Request $request, string $eventId): JsonResponse
    {
        return response()->json(['data' => $this->drafts->get($eventId, $this->actor($request))]);
    }

    public function update(Request $request, string $eventId): JsonResponse
    {
        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:1']]);
        $input = $request->except('expected_version');

        return response()->json(['data' => $this->drafts->update(
            $eventId, $this->actor($request), (int) $data['expected_version'], $input,
        )]);
    }

    public function submit(Request $request, string $eventId): JsonResponse
    {
        return $this->transition($this->workflow->submitForReview(...), $request, $eventId);
    }

    public function approvePrices(Request $request, string $eventId): JsonResponse
    {
        return $this->transition($this->workflow->approvePrices(...), $request, $eventId);
    }

    public function approveReview(Request $request, string $eventId): JsonResponse
    {
        return $this->transition($this->workflow->approvePublication(...), $request, $eventId);
    }

    public function reject(Request $request, string $eventId): JsonResponse
    {
        $data = $request->validate([
            'expected_version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        return response()->json(['data' => $this->workflow->reject(
            $eventId, $this->actor($request), (int) $data['expected_version'], $data['reason'],
        )]);
    }

    /** @param callable(string, string, int): array<string, mixed> $transition */
    private function transition(callable $transition, Request $request, string $eventId): JsonResponse
    {
        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:1']]);

        return response()->json(['data' => $transition($eventId, $this->actor($request), (int) $data['expected_version'])]);
    }

    private function actor(Request $request): string
    {
        $actor = (string) $request->attributes->get('identity.user_id', '');
        abort_unless(Str::isUuid($actor), 401, 'Authenticated actor context is missing.');

        return $actor;
    }
}
