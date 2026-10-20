<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Http\Requests;

use App\Modules\Reporting\Domain\Enums\ReportType;
use Illuminate\Foundation\Http\FormRequest;

final class SubmitReportJobRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'report_type' => ['required', 'string', 'in:'.implode(',', array_column(ReportType::cases(), 'value'))],
            'filters'     => ['required', 'array'],
        ];
    }

    public function resolvedType(): ReportType
    {
        return ReportType::from($this->validated('report_type'));
    }

    public function piiAccess(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        // Check compliance.pii.read permission via IdentityModuleContract
        try {
            $identity = app(\App\Modules\Identity\Domain\Contracts\IdentityModuleContract::class);
            return $identity->hasPermission((string) $user->getAuthIdentifier(), 'compliance.pii.read');
        } catch (\Throwable) {
            return false;
        }
    }
}
