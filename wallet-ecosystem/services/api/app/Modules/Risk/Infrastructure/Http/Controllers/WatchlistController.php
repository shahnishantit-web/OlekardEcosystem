<?php

declare(strict_types=1);

namespace App\Modules\Risk\Infrastructure\Http\Controllers;

use App\Modules\Risk\Domain\Models\WatchlistEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class WatchlistController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'full_name'     => ['required', 'string', 'max:255'],
            'entry_type'    => ['required', 'string', 'in:sanctions,pep,internal_blocklist,adverse_media'],
            'source'        => ['nullable', 'string', 'max:255'],
            'date_of_birth' => ['nullable', 'date_format:Y-m-d'],
            'nationality'   => ['nullable', 'string', 'size:2'],
            'id_numbers'    => ['nullable', 'array'],
            'aliases'       => ['nullable', 'array'],
            'listed_at'     => ['nullable', 'date'],
            'expires_at'    => ['nullable', 'date', 'after:today'],
            'notes'         => ['nullable', 'string'],
        ]);

        $entry = WatchlistEntry::create(array_merge($validated, [
            'id'        => (string) Str::orderedUuid(),
            'is_active' => true,
            'listed_at' => $validated['listed_at'] ?? now(),
        ]));

        return response()->json($entry->fresh(), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $entry = WatchlistEntry::find($id);

        if ($entry === null) {
            return response()->json(['message' => 'Watchlist entry not found.'], 404);
        }

        $validated = $request->validate([
            'full_name'     => ['sometimes', 'string', 'max:255'],
            'entry_type'    => ['sometimes', 'string', 'in:sanctions,pep,internal_blocklist,adverse_media'],
            'source'        => ['nullable', 'string', 'max:255'],
            'date_of_birth' => ['nullable', 'date_format:Y-m-d'],
            'nationality'   => ['nullable', 'string', 'size:2'],
            'id_numbers'    => ['nullable', 'array'],
            'aliases'       => ['nullable', 'array'],
            'expires_at'    => ['nullable', 'date', 'after:today'],
            'notes'         => ['nullable', 'string'],
        ]);

        $entry->update($validated);

        return response()->json($entry->fresh());
    }

    public function deactivate(Request $request, string $id): JsonResponse
    {
        $entry = WatchlistEntry::find($id);

        if ($entry === null) {
            return response()->json(['message' => 'Watchlist entry not found.'], 404);
        }

        if (!$entry->is_active) {
            return response()->json(['message' => 'Entry is already inactive.'], 409);
        }

        $entry->update(['is_active' => false]);

        return response()->json(['message' => 'Entry deactivated.']);
    }
}
