<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Http\Resources;

use App\Modules\Reporting\Infrastructure\Models\ReportDefinitionModel;
use Illuminate\Http\Resources\Json\JsonResource;

final class ReportDefinitionResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var ReportDefinitionModel $definition */
        $definition = $this->resource;

        return [
            'id'                  => $definition->id,
            'type'                => $definition->type,
            'name'                => $definition->name,
            'required_permission' => $definition->required_permission,
            'filter_schema'       => $definition->filter_schema,
        ];
    }
}
