<?php

declare(strict_types=1);

namespace App\Modules\Support\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AddTicketNoteRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function prepareForValidation(): void
    {
        $actorType = (string) $this->attributes->get('identity.actor_type', 'customer');
        if (in_array($actorType, ['customer', 'agent'], true)) {
            $this->merge(['is_internal' => false]);
        }
    }

    public function rules(): array
    {
        return [
            'content'     => ['required', 'string'],
            'is_internal' => ['sometimes', 'boolean'],
        ];
    }
}
