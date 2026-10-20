<?php

declare(strict_types=1);

namespace App\Modules\Service\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ValidateCustomerReferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_reference' => ['required', 'string', 'max:255'],
        ];
    }
}
