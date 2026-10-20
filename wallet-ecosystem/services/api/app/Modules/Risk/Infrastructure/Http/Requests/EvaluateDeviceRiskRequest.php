<?php

declare(strict_types=1);

namespace App\Modules\Risk\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class EvaluateDeviceRiskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'device_id'     => ['required', 'string', 'max:128'],
            'customer_id'   => ['required', 'uuid'],
            'is_new_device' => ['required', 'boolean'],
        ];
    }
}
