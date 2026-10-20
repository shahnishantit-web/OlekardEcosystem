<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RejectChangeRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Gated at route level via configuration.approve middleware
    }

    public function rules(): array
    {
        return [
            'reason'          => ['required', 'string', 'max:1000'],
            'idempotency_key' => ['required', 'string', 'max:255'],
        ];
    }

    public function idempotencyKey(): string
    {
        return (string) $this->input('idempotency_key', '');
    }
}
