<?php

declare(strict_types=1);

use App\Modules\Fees\Domain\Enums\FeeRuleStatus;
use App\Modules\Fees\Domain\Enums\FeeSubjectType;
use App\Modules\Fees\Domain\Enums\FeeTransactionType;
use App\Modules\Fees\Domain\Enums\FeeType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Bootstrap placeholder — admin must update amount_minor to real settlement fee before activating settlement adapter
return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        DB::connection('platform')->table('fees.fee_rules')->insert([
            'id'               => Str::orderedUuid()->toString(),
            'transaction_type' => FeeTransactionType::Settlement->value,
            'fee_type'         => FeeType::Fixed->value,
            'currency'         => 'BWP',
            'subject_type'     => FeeSubjectType::Global->value,
            'subject_id'       => null,
            'rate'             => null,
            'amount_minor'     => 1,
            'min_fee_minor'    => null,
            'max_fee_minor'    => null,
            'valid_from'       => now(),
            'valid_to'         => null,
            'status'           => FeeRuleStatus::Active->value,
            'pricing_version_id' => null,
            'created_by'       => 'system',
            'idempotency_key'  => Str::uuid()->toString(),
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }

    public function down(): void
    {
        DB::connection('platform')
            ->table('fees.fee_rules')
            ->where('transaction_type', FeeTransactionType::Settlement->value)
            ->where('created_by', 'system')
            ->delete();
    }
};
