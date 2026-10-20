<?php

declare(strict_types=1);

namespace App\Modules\Integration\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateIntegrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'display_name'          => ['sometimes', 'string', 'max:255'],
            'credentials_reference' => ['sometimes', 'string', 'max:255'],
            'endpoint_override'     => ['sometimes', 'nullable', 'url', 'max:500'],
            'configuration'         => ['sometimes', 'nullable', 'array'],
        ];
    }
}
