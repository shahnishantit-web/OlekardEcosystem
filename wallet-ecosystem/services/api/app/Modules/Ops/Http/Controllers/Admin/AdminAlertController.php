<?php

declare(strict_types=1);

namespace App\Modules\Ops\Http\Controllers\Admin;

use App\Modules\Ops\Domain\Contracts\AlertRepositoryInterface;
use App\Modules\Ops\Domain\Enums\AlertStatus;
use App\Modules\Ops\Http\Controllers\OpsBaseController;
use App\Modules\Ops\Http\Resources\AlertResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Carbon\Carbon;

final class AdminAlertController extends OpsBaseController
{
    public function __construct(
        private readonly AlertRepositoryInterface $alerts,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $status   = $request->query('status') ? AlertStatus::from($request->query('status')) : null;
        $severity = $request->query('severity');
        $from     = $request->query('from') ? Carbon::parse($request->query('from')) : null;
        $perPage  = min((int) $request->query('per_page', 25), 100);
        $cursor   = $request->query('cursor');

        $paginator = $this->alerts->paginate($status, $severity, $from, $perPage, $cursor);

        return $this->paginatedOk(
            $paginator->through(fn ($alert) => new AlertResource($alert)),
            '',
        );
    }
}
