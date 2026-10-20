<?php

declare(strict_types=1);

namespace App\Modules\Commission\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SetAgentTierRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'agent_tier' => ['nullable', 'string', 'max:50'],
        ];
    }
}
