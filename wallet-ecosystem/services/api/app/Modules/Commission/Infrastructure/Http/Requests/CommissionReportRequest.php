<?php

declare(strict_types=1);

namespace App\Modules\Commission\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CommissionReportRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'agent_id'        => ['sometimes', 'string', 'uuid'],
            'status'          => ['sometimes', 'string', 'in:pending,approved,paid,reversed'],
            'transaction_type'=> ['sometimes', 'string', 'in:cash_in,cash_out'],
            'from'            => ['sometimes', 'date'],
            'to'              => ['sometimes', 'date', 'after_or_equal:from'],
            'page'            => ['sometimes', 'integer', 'min:1'],
            'per_page'        => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
