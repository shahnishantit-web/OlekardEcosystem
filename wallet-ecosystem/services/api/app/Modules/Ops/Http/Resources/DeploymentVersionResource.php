<?php

declare(strict_types=1);

namespace App\Modules\Ops\Http\Resources;

use App\Modules\Ops\Infrastructure\Models\DeploymentVersionModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DeploymentVersionModel */
final class DeploymentVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'version'       => $this->version,
            'git_sha'       => $this->git_sha,
            'release_notes' => $this->release_notes,
            'deployed_by'   => $this->deployed_by,
            'deployed_at'   => $this->deployed_at?->toAtomString(),
            'is_current'    => $this->is_current,
        ];
    }
}
