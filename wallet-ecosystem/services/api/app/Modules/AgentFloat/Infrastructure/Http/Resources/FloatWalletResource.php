<?php

declare(strict_types=1);

namespace App\Modules\AgentFloat\Infrastructure\Http\Resources;

use App\Modules\AgentFloat\Infrastructure\Models\AgentFloatWalletModel;
use App\Modules\AgentFloat\Infrastructure\Models\FloatLimitModel;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Wallet\Infrastructure\Models\WalletModel;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AgentFloatWalletModel
 */
final class FloatWalletResource extends JsonResource
{
    public function __construct(
        AgentFloatWalletModel $resource,
        private readonly LedgerModuleContract $ledger,
        private readonly ?WalletModel $walletModel = null,
        private readonly ?FloatLimitModel $limits = null,
    ) {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray($request): array
    {
        /** @var AgentFloatWalletModel $floatWallet */
        $floatWallet = $this->resource;

        $balance = $this->ledger->getBalance($floatWallet->ledger_account_reference);
        $status  = $this->walletModel?->status ?? 'active';

        $lowFloatAlert = false;
        if ($this->limits !== null && ! $this->limits->low_threshold_is_tbd) {
            $lowFloatAlert = $balance < ($this->limits->low_float_threshold ?? PHP_INT_MAX);
        }

        return [
            'id'                       => $floatWallet->id,
            'agent_id'                 => $floatWallet->agent_id,
            'wallet_id'                => $floatWallet->wallet_id,
            'ledger_account_reference' => $floatWallet->ledger_account_reference,
            'status'                   => $status instanceof \BackedEnum ? $status->value : $status,
            'balance'                  => $balance,
            'low_float_alert'          => $lowFloatAlert,
            'created_at'               => $floatWallet->created_at?->toISOString(),
        ];
    }
}
