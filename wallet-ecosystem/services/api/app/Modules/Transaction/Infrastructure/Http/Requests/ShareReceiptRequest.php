<?php

declare(strict_types=1);

namespace App\Modules\Transaction\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ShareReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'channel'   => ['required', 'string', 'in:email,sms'],
            'recipient' => ['required', 'string', 'max:255'],
        ];
    }
}
