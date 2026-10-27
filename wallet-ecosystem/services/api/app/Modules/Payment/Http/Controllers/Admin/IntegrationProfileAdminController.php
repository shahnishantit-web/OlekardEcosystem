<?php

declare(strict_types=1);

namespace App\Modules\Payment\Http\Controllers\Admin;

use App\Modules\Payment\Domain\Contracts\IntegrationProfileRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Read-only controller for the integration profile catalogue.
 * Requires payment.configuration permission (enforced via route middleware).
 */
final class IntegrationProfileAdminController extends Controller
{
    public function __construct(
        private readonly IntegrationProfileRepositoryInterface $profileRepository,
    ) {
    }

    public function index(): JsonResponse
    {
        $profiles = $this->profileRepository->listActive();

        return response()->json(['data' => $profiles->values()]);
    }
}
