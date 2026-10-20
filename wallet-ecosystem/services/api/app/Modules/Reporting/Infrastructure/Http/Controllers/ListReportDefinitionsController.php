<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Http\Controllers;

use App\Modules\Identity\Domain\Contracts\IdentityModuleContract;
use App\Modules\Reporting\Infrastructure\Http\Resources\ReportDefinitionResource;
use App\Modules\Reporting\Infrastructure\Models\ReportDefinitionModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ListReportDefinitionsController
{
    public function __construct(
        private readonly IdentityModuleContract $identity,
    ) {}

    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $userId = (string) $request->attributes->get('identity.user_id', '');

        $definitions = ReportDefinitionModel::active()
            ->get()
            ->filter(fn ($def) => $this->identity->hasPermission($userId, $def->required_permission))
            ->values();

        return ReportDefinitionResource::collection($definitions);
    }
}
