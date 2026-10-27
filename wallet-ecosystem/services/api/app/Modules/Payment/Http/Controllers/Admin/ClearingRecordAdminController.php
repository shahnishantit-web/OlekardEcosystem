<?php

declare(strict_types=1);

namespace App\Modules\Payment\Http\Controllers\Admin;

use App\Modules\Payment\Domain\Contracts\ClearingRecordRepositoryInterface;
use App\Modules\Payment\Http\Resources\ClearingRecordListResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

final class ClearingRecordAdminController extends Controller
{
    public function __construct(
        private readonly ClearingRecordRepositoryInterface $repository,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = [
            'provider_code'     => $request->input('provider_code'),
            'accounting_status' => $request->input('accounting_status'),
        ];

        if ($request->has('aged')) {
            $filters['aged'] = filter_var($request->input('aged'), FILTER_VALIDATE_BOOLEAN);
        }

        if ($request->has('bank_settled')) {
            $filters['bank_settled'] = filter_var($request->input('bank_settled'), FILTER_VALIDATE_BOOLEAN);
        }

        $filters = array_filter($filters, fn ($v): bool => $v !== null);

        $perPage = (int) $request->input('per_page', 20);
        $paginator = $this->repository->list($filters, $perPage);

        return ClearingRecordListResource::collection($paginator);
    }
}
