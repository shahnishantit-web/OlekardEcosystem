<?php

declare(strict_types=1);

namespace App\Modules\Support\Infrastructure\Http\Requests;

use App\Modules\Support\Domain\Enums\TicketCategory;
use App\Modules\Support\Domain\Enums\TicketPriority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

final class CreateSupportTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'subject'     => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'category'    => ['required', 'string', new Enum(TicketCategory::class)],
            'priority'    => ['sometimes', 'string', new Enum(TicketPriority::class)],
        ];
    }
}
