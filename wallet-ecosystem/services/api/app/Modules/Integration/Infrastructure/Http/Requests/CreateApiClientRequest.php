<?php

declare(strict_types=1);

namespace App\Modules\Integration\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CreateApiClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'                  => ['required', 'string', 'max:255'],
            'permissions'           => ['sometimes', 'array'],
            'permissions.*'         => ['string'],
            'rate_limit_per_minute' => ['sometimes', 'integer', 'min:1', 'max:10000'],
        ];
    }
}
