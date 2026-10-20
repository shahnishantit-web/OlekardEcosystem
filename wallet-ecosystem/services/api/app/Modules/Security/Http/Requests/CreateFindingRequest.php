<?php

declare(strict_types=1);

namespace App\Modules\Security\Http\Requests;

use App\Modules\Security\Domain\Enums\VulnerabilityFindingSeverity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

final class CreateFindingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'scanner' => ['required', 'string', 'max:100'],
            'finding_ref' => ['required', 'string', 'max:255'],
            'severity' => ['required', 'string', new Enum(VulnerabilityFindingSeverity::class)],
            'component' => ['required', 'string', 'max:255'],
            'title' => ['required', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:5000'],
            'cvss_score' => ['nullable', 'numeric', 'min:0', 'max:10', 'decimal:0,1'],
            'remediation_steps' => ['nullable', 'string', 'max:20000'],
        ];
    }

    public function idempotencyKey(): string
    {
        return (string) $this->header('Idempotency-Key', '');
    }
}
