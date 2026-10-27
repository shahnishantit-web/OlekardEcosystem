<?php

declare(strict_types=1);

namespace App\Modules\Payment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class GetPaymentHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<string>> */
    public function rules(): array
    {
        return [
            'purpose_type'   => ['sometimes', 'string', 'max:100'],
            'status'         => ['sometimes', 'string', 'in:processing,confirmed,refund_pending,refunded,failed,cancelled'],
            'date_from'      => ['sometimes', 'date'],
            'date_to'        => ['sometimes', 'date', 'after_or_equal:date_from'],
            'currency_code'  => ['sometimes', 'string', 'regex:/^[A-Z]{3}$/'],
            'organisation_id' => ['sometimes', 'string', 'max:100'],
            'page'           => ['sometimes', 'integer', 'min:1'],
            'per_page'       => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
