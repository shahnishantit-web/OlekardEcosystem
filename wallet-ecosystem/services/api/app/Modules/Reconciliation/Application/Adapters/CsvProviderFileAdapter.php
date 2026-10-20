<?php

declare(strict_types=1);

namespace App\Modules\Reconciliation\Application\Adapters;

use App\Modules\Reconciliation\Domain\Adapters\ProviderFileAdapterInterface;
use App\Modules\Reconciliation\Domain\Adapters\ProviderTransactionDto;
use RuntimeException;

final class CsvProviderFileAdapter implements ProviderFileAdapterInterface
{
    /**
     * Expected CSV columns: provider_reference, amount, currency_code, transaction_date
     * Additional columns are passed through as metadata.
     */
    public function parse(mixed $fileStream, string $mimeType): array
    {
        if (! is_resource($fileStream)) {
            throw new RuntimeException('CsvProviderFileAdapter requires a valid file resource.');
        }

        $rows    = [];
        $headers = null;

        while (($line = fgetcsv($fileStream)) !== false) {
            if ($headers === null) {
                $headers = array_map('trim', $line);
                continue;
            }

            if (count($headers) !== count($line)) {
                continue;
            }

            $row = array_combine($headers, $line);

            $rows[] = new ProviderTransactionDto(
                providerReference: trim((string) ($row['provider_reference'] ?? '')),
                amount:            trim((string) ($row['amount'] ?? '0')),
                currencyCode:      strtoupper(trim((string) ($row['currency_code'] ?? 'BWP'))),
                transactionDate:   trim((string) ($row['transaction_date'] ?? '')),
                itemType:          'provider_transaction',
                metadata:          $row,
            );
        }

        return $rows;
    }
}
