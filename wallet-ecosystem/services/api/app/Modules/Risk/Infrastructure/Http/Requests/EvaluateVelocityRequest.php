<?php

declare(strict_types=1);

namespace App\Modules\Risk\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class EvaluateVelocityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id'      => ['required', 'uuid'],
            'transaction_type' => ['required', 'string', Rule::in([
                'funding', 'transfer', 'withdrawal', 'bill_payment', 'qr_payment', 'commission',
            ])],
            'window_seconds'   => ['required', 'integer', Rule::in([3600, 86400, 604800, 2592000])],
        ];
    }
}
