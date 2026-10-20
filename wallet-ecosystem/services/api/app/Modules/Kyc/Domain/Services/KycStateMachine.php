<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Domain\Services;

use App\Modules\Kyc\Domain\Enums\KycCaseStatus;
use App\Modules\Kyc\Domain\Exceptions\KycInvalidStatusTransitionException;

final class KycStateMachine
{
    private const TRANSITIONS = [
        // (from) => [(action) => (to)]
        'initiated' => [
            'afterDocumentUploaded' => 'documents_submitted',
            'submit'                => 'under_review',
        ],
        'documents_submitted' => [
            'submit' => 'under_review',
        ],
        'under_review' => [
            'approve'     => 'approved',
            'reject'      => 'rejected',
            'requestInfo' => 'needs_info',
        ],
        'rejected' => [
            'resubmit' => 'initiated',
        ],
        'needs_info' => [
            'resubmit' => 'initiated',
        ],
        'approved' => [
            'expire'                => 'expired',
            'triggerReverification' => 'initiated',
        ],
        'expired' => [
            'triggerReverification' => 'initiated',
        ],
    ];

    public function transition(KycCaseStatus $from, string $action): KycCaseStatus
    {
        $next = self::TRANSITIONS[$from->value][$action] ?? null;

        if ($next === null) {
            throw new KycInvalidStatusTransitionException($from, $action);
        }

        return KycCaseStatus::from($next);
    }

    public function canTransition(KycCaseStatus $from, string $action): bool
    {
        return isset(self::TRANSITIONS[$from->value][$action]);
    }
}
