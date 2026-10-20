enum TransferStatus { pending, completed, failed }

class WalletLookupResult {
  final String walletNumber;
  final String accountName;
  const WalletLookupResult({required this.walletNumber, required this.accountName});
}

class TransferPreview {
  final String recipientName;
  final String recipientWalletNumber;
  final int amountMinorUnits;
  final int feeMinorUnits;
  final String currencyCode;
  const TransferPreview({
    required this.recipientName,
    required this.recipientWalletNumber,
    required this.amountMinorUnits,
    required this.feeMinorUnits,
    required this.currencyCode,
  });
}

class TransferResult {
  final String transactionId;
  final String referenceNumber;
  final TransferStatus status;
  final int amountMinorUnits;
  final int feeMinorUnits;
  final String currencyCode;
  final String recipientName;
  final String recipientWalletNumber;
  final DateTime createdAt;

  const TransferResult({
    required this.transactionId,
    required this.referenceNumber,
    required this.status,
    required this.amountMinorUnits,
    required this.feeMinorUnits,
    required this.currencyCode,
    required this.recipientName,
    required this.recipientWalletNumber,
    required this.createdAt,
  });
}

class TransferRequestDto {
  final String idempotencyKey;
  final String recipientWalletNumber;
  final int amountMinorUnits;
  final String currencyCode;
  final String? note;

  const TransferRequestDto({
    required this.idempotencyKey,
    required this.recipientWalletNumber,
    required this.amountMinorUnits,
    required this.currencyCode,
    this.note,
  });

  Map<String, dynamic> toJson() => {
        'recipient_wallet_number': recipientWalletNumber,
        'amount_minor_units': amountMinorUnits,
        'currency_code': currencyCode,
        if (note != null) 'note': note,
      };
}
