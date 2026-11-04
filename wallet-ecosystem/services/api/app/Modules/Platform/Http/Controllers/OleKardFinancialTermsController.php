<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Platform\Application\Services\OleKardFinancialTermsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class OleKardFinancialTermsController extends Controller
{
    public function __construct(private readonly OleKardFinancialTermsService $terms) {}

    public function draft(Request $request): JsonResponse
    {
        $data = $request->validate([
            'content' => ['required', 'array'], 'effective_from' => ['required', 'date'],
            'effective_until' => ['nullable', 'date', 'after:effective_from'],
        ]);

        return response()->json(['data' => $this->terms->draft(
            $data['content'], $data['effective_from'], $data['effective_until'] ?? null,
            $this->actor($request), $this->idempotencyKey($request), $this->correlationId($request),
        )], 201);
    }

    public function approve(Request $request, string $versionId): JsonResponse
    {
        $data = $request->validate(['approval_reference' => ['required', 'string', 'max:255']]);

        return response()->json(['data' => $this->terms->approve(
            $versionId, $this->actor($request), $data['approval_reference'], $this->correlationId($request),
        )]);
    }

    public function publish(Request $request, string $versionId): JsonResponse
    {
        return response()->json(['data' => $this->terms->publish($versionId, $this->actor($request), $this->correlationId($request))]);
    }

    public function accept(Request $request, string $organisationId, string $eventId, string $versionId): JsonResponse
    {
        return response()->json(['data' => $this->terms->accept(
            $versionId, $organisationId, $eventId, $this->actor($request),
            $this->idempotencyKey($request), $this->correlationId($request),
        )], 201);
    }

    private function actor(Request $request): string
    {
        $actor = (string) $request->attributes->get('identity.user_id', '');
        abort_if($actor === '', 401, 'Authenticated actor context is required.');

        return $actor;
    }

    private function idempotencyKey(Request $request): string
    {
        $key = (string) $request->header('Idempotency-Key', '');
        abort_if($key === '' || strlen($key) > 128, 422, 'A bounded Idempotency-Key is required.');

        return $key;
    }

    private function correlationId(Request $request): string
    {
        $candidate = $request->header('X-Correlation-ID');

        return is_string($candidate) && Str::isUuid($candidate) ? $candidate : (string) Str::uuid();
    }
}
