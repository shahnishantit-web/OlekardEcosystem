<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Infrastructure\Http\Requests;

use App\Modules\Wallet\Domain\Enums\WalletStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

final class UpdateWalletStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', new Enum(WalletStatus::class)],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
