<?php

declare(strict_types=1);

namespace App\Modules\Agent\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CreateApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'agent_type' => ['required', 'string', 'in:individual,business'],
        ];
    }
}
