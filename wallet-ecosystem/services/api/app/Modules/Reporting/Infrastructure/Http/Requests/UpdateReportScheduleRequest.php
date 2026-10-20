<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateReportScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'                       => ['sometimes', 'string', 'max:255'],
            'filters'                    => ['sometimes', 'array'],
            'filters.date_range_start'   => ['sometimes', 'date_format:Y-m-d'],
            'filters.date_range_end'     => ['sometimes', 'date_format:Y-m-d'],
            'cadence'                    => ['sometimes', Rule::in(['daily', 'weekly', 'monthly'])],
            'cadence_time'               => ['sometimes', 'regex:/^\d{2}:\d{2}$/'],
            'cadence_day_of_week'        => ['sometimes', 'nullable', 'integer', 'between:0,6'],
            'cadence_day_of_month'       => ['sometimes', 'nullable', 'integer', 'between:1,28'],
            'recipients'                 => ['sometimes', 'array'],
            'recipients.*.user_id'       => ['required_with:recipients', 'uuid'],
            'recipients.*.email'         => ['sometimes', 'nullable', 'email'],
            'email_notification_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
