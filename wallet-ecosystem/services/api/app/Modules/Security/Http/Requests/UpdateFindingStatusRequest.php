<?php

declare(strict_types=1);

namespace App\Modules\Security\Http\Requests;

use App\Modules\Security\Domain\Enums\VulnerabilityFindingStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

final class UpdateFindingStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status'                   => ['required', 'string', new Enum(VulnerabilityFindingStatus::class)],
            'suppression_justification' => [
                'nullable',
                'string',
                'max:1000',
                'required_if:status,' . VulnerabilityFindingStatus::Suppressed->value,
            ],
        ];
    }
}
