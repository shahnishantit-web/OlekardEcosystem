<?php

declare(strict_types=1);

namespace App\Modules\Payment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CreatePaymentExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<string>> */
    public function rules(): array
    {
        return [
            'scope_type'            => ['required', 'string', 'in:organisation,platform'],
            'organisation_id'       => ['required_if:scope_type,organisation', 'nullable', 'string', 'max:100'],
            'filters'               => ['sometimes', 'array'],
            'filters.purpose_type'  => ['sometimes', 'string', 'max:100'],
            'filters.currency_code' => ['sometimes', 'string', 'regex:/^[A-Z]{3}$/'],
            'filters.date_from'     => ['sometimes', 'date'],
            'filters.date_to'       => ['sometimes', 'date', 'after_or_equal:filters.date_from'],
            'filters.status'        => ['sometimes', 'string', 'in:pending,processing,succeeded,failed,cancelled,expired,refunding,partially_refunded,refunded'],
        ];
    }
}
