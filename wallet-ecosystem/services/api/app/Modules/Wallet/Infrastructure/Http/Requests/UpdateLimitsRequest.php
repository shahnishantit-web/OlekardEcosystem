<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateLimitsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'limits'                   => ['required', 'array', 'min:1'],
            'limits.*.limit_type'      => ['required', 'string'],
            'limits.*.currency_code'   => ['required', 'string', 'max:10'],
            'limits.*.limit_value'     => ['required', 'integer', 'min:1'],
        ];
    }
}
