<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AcknowledgeNewDeviceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'acknowledged' => ['required', 'boolean'],
        ];
    }
}
