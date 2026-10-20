<?php

declare(strict_types=1);

namespace App\Modules\Agent\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SearchCustomersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search'   => ['required', 'string'],
            'page'     => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
