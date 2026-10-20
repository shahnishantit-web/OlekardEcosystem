<?php

declare(strict_types=1);

namespace App\Modules\Service\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ServiceCallbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'transaction_id'    => ['nullable', 'uuid'],
            'status'            => ['required', 'string', 'in:success,failure,unknown'],
            'provider_reference' => ['required', 'string', 'max:255'],
            'reason'            => ['nullable', 'string', 'max:500'],
        ];
    }
}
