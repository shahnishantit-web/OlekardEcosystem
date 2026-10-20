<?php

declare(strict_types=1);

namespace App\Modules\Risk\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class InvestigateAlertRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'alert_id'    => ['required', 'uuid'],
            'assigned_to' => ['required', 'uuid'],
            'version'     => ['required', 'integer', 'min:1'],
        ];
    }
}
