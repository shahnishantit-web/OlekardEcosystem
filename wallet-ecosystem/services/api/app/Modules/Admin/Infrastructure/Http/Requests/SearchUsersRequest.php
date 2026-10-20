<?php

declare(strict_types=1);

namespace App\Modules\Admin\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SearchUsersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q'         => ['nullable', 'string', 'max:255'],
            'status'    => ['nullable', 'string', 'in:active,suspended,all'],
            'kyc_tier'  => ['nullable', 'integer', 'min:0', 'max:3'],
            'kyc_status' => ['nullable', 'string', 'max:30'],
            'date_from' => ['nullable', 'date'],
            'date_to'   => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page'  => ['nullable', 'integer', 'min:1', 'max:100'],
            'page'      => ['nullable', 'integer', 'min:1'],
        ];
    }
}
