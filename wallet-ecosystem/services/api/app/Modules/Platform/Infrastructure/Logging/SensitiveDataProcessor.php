<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Logging;

use App\Modules\Platform\Infrastructure\Security\PiiMasker;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Strips sensitive field values from log context and extra before any handler writes them.
 * Extends PiiMasker defaults with payment, identity, and integration-specific keys.
 */
final class SensitiveDataProcessor implements ProcessorInterface
{
    private const SENSITIVE_KEYS = [
        'password',
        'pin',
        'otp',
        'secret',
        'token',
        'card_number',
        'cvv',
        'id_number',
        'passport',
        'national_id',
        'account_number',
        'wallet_number',
        'iban',
        'bban',
        'raw_payload',
        'credentials_reference',
        'raw_secret',
        'document_number',
        'extracted_data',
        'date_of_birth',
    ];

    public function __invoke(LogRecord $record): LogRecord
    {
        $context = PiiMasker::maskFields($record->context, self::SENSITIVE_KEYS);
        $extra   = PiiMasker::maskFields($record->extra,   self::SENSITIVE_KEYS);

        return $record->with(context: $context, extra: $extra);
    }
}
