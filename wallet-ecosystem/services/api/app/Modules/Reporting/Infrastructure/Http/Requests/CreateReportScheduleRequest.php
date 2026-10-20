<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreateReportScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'                       => ['required', 'string', 'max:255'],
            'report_type'                => ['required', 'string'],
            'filters'                    => ['sometimes', 'array'],
            'filters.date_range_start'   => ['required_with:filters', 'date_format:Y-m-d'],
            'filters.date_range_end'     => ['required_with:filters', 'date_format:Y-m-d'],
            'cadence'                    => ['required', Rule::in(['daily', 'weekly', 'monthly'])],
            'cadence_time'               => ['required', 'regex:/^\d{2}:\d{2}$/'],
            'cadence_day_of_week'        => ['required_if:cadence,weekly', 'nullable', 'integer', 'between:0,6'],
            'cadence_day_of_month'       => ['required_if:cadence,monthly', 'nullable', 'integer', 'between:1,28'],
            'recipients'                 => ['sometimes', 'array'],
            'recipients.*.user_id'       => ['required', 'uuid'],
            'recipients.*.email'         => ['sometimes', 'nullable', 'email'],
            'email_notification_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
