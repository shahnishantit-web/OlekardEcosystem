<?php

declare(strict_types=1);

namespace App\Modules\Transaction\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class InitiateRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['sometimes', 'string', 'max:1000'],
        ];
    }

    public function idempotencyKey(): string
    {
        $key = $this->header('Idempotency-Key');

        if (empty($key) || ! is_string($key)) {
            abort(400, 'Idempotency-Key header is required.');
        }

        return $key;
    }
}
