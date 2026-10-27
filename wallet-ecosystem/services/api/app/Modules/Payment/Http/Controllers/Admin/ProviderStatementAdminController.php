<?php

declare(strict_types=1);

namespace App\Modules\Payment\Http\Controllers\Admin;

use App\Modules\Payment\Application\Jobs\PayoutQualificationJob;
use App\Modules\Payment\Domain\Contracts\ProviderStatementRepositoryInterface;
use App\Modules\Payment\Http\Resources\ProviderStatementDetailResource;
use App\Modules\Payment\Http\Resources\ProviderStatementListResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

final class ProviderStatementAdminController extends Controller
{
    public function __construct(
        private readonly ProviderStatementRepositoryInterface $repository,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->only(['provider_code', 'status', 'currency_code', 'period_from', 'period_to']);
        $perPage = (int) $request->input('per_page', 20);

        $paginator = $this->repository->list($filters, $perPage);

        return ProviderStatementListResource::collection($paginator);
    }

    public function show(Request $request, string $statementId): JsonResponse
    {
        $statement = $this->repository->findById($statementId);

        if ($statement === null) {
            return response()->json(['message' => 'Statement not found.'], 404);
        }

        $matchStatusFilter = $request->input('match_status');
        $linesQuery = $statement->lines();
        if ($matchStatusFilter !== null) {
            $linesQuery->where('match_status', $matchStatusFilter);
        }
        $statement->setRelation('lines', $linesQuery->get());

        return response()->json(['data' => (new ProviderStatementDetailResource($statement))->toArray($request)]);
    }

    public function qualify(Request $request, string $statementId): JsonResponse
    {
        $statement = $this->repository->findById($statementId);

        if ($statement === null) {
            return response()->json(['message' => 'Statement not found.'], 404);
        }

        if (!$statement->status->allowsQualification()) {
            return response()->json([
                'message' => "Cannot qualify a statement with status '{$statement->status->value}'. Only 'matched' or 'partially_matched' statements can be qualified.",
            ], 409);
        }

        PayoutQualificationJob::dispatch($statementId);

        return response()->json(['message' => 'Payout qualification job dispatched.'], 202);
    }
}
