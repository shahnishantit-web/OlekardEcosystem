<?php

declare(strict_types=1);

namespace App\Modules\Admin\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SearchTransactionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q'         => ['sometimes', 'string', 'max:200'],
            'type'      => ['sometimes', 'string', 'in:funding,transfer,withdrawal,refund'],
            'status'    => ['sometimes', 'string', 'in:pending,completed,failed,reversed,all'],
            'wallet_id' => ['sometimes', 'uuid'],
            'from'      => ['sometimes', 'date'],
            'to'        => ['sometimes', 'date', 'after_or_equal:from'],
            'per_page'  => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page'      => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
