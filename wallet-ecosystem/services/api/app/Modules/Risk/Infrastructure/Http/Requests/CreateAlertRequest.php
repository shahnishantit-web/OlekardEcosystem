<?php

declare(strict_types=1);

namespace App\Modules\Risk\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CreateAlertRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'alert_type'     => ['required', 'string', 'in:velocity,watchlist,pattern,auth_failure,threshold_breach,rapid_movement'],
            'severity'       => ['required', 'string', 'in:low,medium,high,critical'],
            'subject_type'   => ['required', 'string', 'max:30'],
            'subject_id'     => ['required', 'uuid'],
            'title'          => ['required', 'string', 'max:255'],
            'description'    => ['required', 'string'],
            'correlation_id' => ['nullable', 'string', 'max:128'],
            'assigned_to'    => ['nullable', 'uuid'],
            'event_ids'      => ['nullable', 'array'],
            'event_ids.*'    => ['uuid'],
        ];
    }
}
