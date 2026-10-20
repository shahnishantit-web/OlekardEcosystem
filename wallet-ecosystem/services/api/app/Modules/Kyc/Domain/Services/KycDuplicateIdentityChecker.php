<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Domain\Services;

use App\Modules\Kyc\Domain\Enums\KycCaseStatus;
use Illuminate\Support\Facades\DB;

final class KycDuplicateIdentityChecker
{
    public function hasApprovedCaseWithHash(string $nationalIdHash, ?string $excludeCaseId = null): bool
    {
        $query = DB::connection('kyc')
            ->table('kyc.kyc_personal_info as pi')
            ->join('kyc.kyc_cases as c', 'c.id', '=', 'pi.kyc_case_id')
            ->where('pi.national_id_hash', $nationalIdHash)
            ->where('c.status', KycCaseStatus::Approved->value);

        if ($excludeCaseId !== null) {
            $query->where('pi.kyc_case_id', '!=', $excludeCaseId);
        }

        return $query->exists();
    }
}
