<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class GenerateFingerprintRequest extends FormRequest
{
    public function rules(): array
    {
        // Attribute-level requirements are enforced by the entropy check in the handler
        // rather than here, so that low-entropy payloads receive 400 INSUFFICIENT_ENTROPY
        // instead of 422 from the validator.
        return [
            'platform'   => ['required', 'string', 'in:ios,android,web'],
            'attributes' => ['required', 'array'],
        ];
    }
}
