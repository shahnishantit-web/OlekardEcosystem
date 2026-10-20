<?php

declare(strict_types=1);

namespace App\Modules\Support\Infrastructure\Http\Requests;

use App\Modules\Support\Domain\Enums\TicketStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

final class UpdateTicketStatusRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'status'          => ['required', 'string', new Enum(TicketStatus::class)],
            'resolution_note' => ['required_if:status,resolved,closed', 'string'],
        ];
    }
}
