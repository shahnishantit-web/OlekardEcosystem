<?php

declare(strict_types=1);

namespace App\Modules\AgentFloat\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateFloatLimitsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'max_balance'           => ['sometimes', 'nullable', 'integer', 'min:1'],
            'per_transaction_limit' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'daily_volume_limit'    => ['sometimes', 'nullable', 'integer', 'min:1'],
            'low_float_threshold'   => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            '*.min' => 'Limit values must be positive integers.',
        ];
    }
}
