<?php

declare(strict_types=1);

namespace App\Modules\Support\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class DisputeRefundRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'amount_minor'                => ['required', 'integer', 'min:1'],
            'currency'                    => ['required', 'string', 'size:3'],
            'partial_refund_justification' => ['sometimes', 'string', 'min:1', 'max:2000'],
        ];
    }
}
