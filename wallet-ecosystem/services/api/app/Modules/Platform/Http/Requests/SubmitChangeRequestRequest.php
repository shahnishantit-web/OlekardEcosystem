<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SubmitChangeRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Gated at route level via configuration.write middleware
    }

    public function rules(): array
    {
        return [
            'config_key'      => ['required', 'string', 'max:255'],
            'proposed_value'  => ['required'],
            'idempotency_key' => ['required', 'string', 'max:255'],
        ];
    }

    public function idempotencyKey(): string
    {
        return (string) $this->input('idempotency_key', '');
    }
}
