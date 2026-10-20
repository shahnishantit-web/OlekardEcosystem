<?php

declare(strict_types=1);

namespace App\Modules\Qa\Http\Controllers;

use App\Modules\Qa\Http\Resources\TestRunDetailResource;
use App\Modules\Qa\Http\Resources\TestRunResource;
use App\Modules\Qa\Models\TestRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class QaRunController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = TestRun::on('platform')->orderBy('created_at', 'desc');

        if ($request->has('category')) {
            $query->where('category', $request->input('category'));
        }

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        $runs = $query->paginate($request->integer('per_page', 20));

        return response()->json([
            'data' => TestRunResource::collection($runs),
            'meta' => [
                'current_page' => $runs->currentPage(),
                'last_page'    => $runs->lastPage(),
                'total'        => $runs->total(),
                'per_page'     => $runs->perPage(),
            ],
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $run = TestRun::on('platform')
            ->with(['results.testCase'])
            ->findOrFail($id);

        return response()->json(['data' => new TestRunDetailResource($run)]);
    }
}
