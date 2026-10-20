<?php

declare(strict_types=1);

namespace App\Modules\Audit\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class GenerateComplianceReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'report_type'      => ['required', 'string', 'in:compliance_summary,kyc_snapshot,transaction_volume'],
            'date_range_start' => ['required', 'date'],
            'date_range_end'   => ['required', 'date', 'after_or_equal:date_range_start'],
            'filters'          => ['nullable', 'array'],
        ];
    }
}
