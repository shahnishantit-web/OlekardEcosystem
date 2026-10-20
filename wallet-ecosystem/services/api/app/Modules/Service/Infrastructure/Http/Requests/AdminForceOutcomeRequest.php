<?php

declare(strict_types=1);

namespace App\Modules\Service\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AdminForceOutcomeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'outcome'           => ['required', 'string', 'in:success,failure'],
            'rationale'         => ['required', 'string', 'max:500'],
            'provider_reference' => ['required_if:outcome,success', 'nullable', 'string', 'max:255'],
        ];
    }
}
