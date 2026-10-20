<?php

declare(strict_types=1);

namespace App\Modules\Qr\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class GenerateQrCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type'            => ['required', 'string', 'in:static,dynamic'],
            'label'           => ['nullable', 'string', 'max:100'],
            'amount'          => ['required_if:type,dynamic', 'prohibited_if:type,static', 'nullable', 'numeric', 'min:0.01'],
            'currency'        => ['nullable', 'string', 'size:3'],
            'merchant_ref'    => ['nullable', 'string', 'max:255'],
            'expiry_seconds'  => ['nullable', 'integer', 'min:60'],
        ];
    }
}
