<?php

declare(strict_types=1);

namespace App\Modules\Integration\Infrastructure\Http\Requests;

use App\Modules\Integration\Domain\Enums\IntegrationProviderType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RegisterIntegrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'provider_type'          => ['required', Rule::enum(IntegrationProviderType::class)],
            'provider_slug'          => ['required', 'string', 'max:64', 'regex:/^[a-z0-9\-_]+$/'],
            'display_name'           => ['required', 'string', 'max:255'],
            'credentials_reference'  => ['required', 'string', 'max:255'],
            'is_default'             => ['sometimes', 'boolean'],
            'endpoint_override'      => ['sometimes', 'nullable', 'url', 'max:500'],
            'configuration'          => ['sometimes', 'nullable', 'array'],
        ];
    }
}
