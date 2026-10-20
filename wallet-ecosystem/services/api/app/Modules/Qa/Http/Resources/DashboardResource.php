<?php

declare(strict_types=1);

namespace App\Modules\Qa\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DashboardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'summary'    => $this->summary,
            'categories' => $this->categories,
        ];
    }
}
