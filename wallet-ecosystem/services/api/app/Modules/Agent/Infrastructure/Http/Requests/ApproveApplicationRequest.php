<?php

declare(strict_types=1);

namespace App\Modules\Agent\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ApproveApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
