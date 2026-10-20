<?php

declare(strict_types=1);

namespace App\Modules\Transaction\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ListTransactionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type'       => ['sometimes', 'string', 'in:funding,transfer,withdrawal,refund'],
            'status'     => ['sometimes', 'string', 'max:30'],
            'search'     => ['sometimes', 'string', 'max:200'],
            'per_page'   => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page'       => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
