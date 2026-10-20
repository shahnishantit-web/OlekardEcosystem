<?php

declare(strict_types=1);

namespace App\Modules\Admin\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ApproveAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['sometimes', 'string', 'min:5', 'max:2000'],
        ];
    }
}
