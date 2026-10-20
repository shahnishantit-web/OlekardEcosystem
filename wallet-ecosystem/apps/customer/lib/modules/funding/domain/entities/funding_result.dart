enum FundingResultStatus { initiated, awaitingPayment, completed, failed }
enum FundingMethod { bankTransfer, agentCashIn }

class FundingResult {
  final String transactionId;
  final String referenceNumber;
  final FundingResultStatus status;
  final int amountMinorUnits;
  final String currencyCode;
  final String? redirectUrl;
  final DateTime createdAt;

  const FundingResult({
    required this.transactionId,
    required this.referenceNumber,
    required this.status,
    required this.amountMinorUnits,
    required this.currencyCode,
    this.redirectUrl,
    required this.createdAt,
  });
}

class FundRequestDto {
  final String idempotencyKey;
  final FundingMethod method;
  final int amountMinorUnits;
  final String currencyCode;

  const FundRequestDto({
    required this.idempotencyKey,
    required this.method,
    required this.amountMinorUnits,
    required this.currencyCode,
  });

  Map<String, dynamic> toJson() => {
        'amount_minor_units': amountMinorUnits,
        'currency_code': currencyCode,
        'method': method == FundingMethod.bankTransfer ? 'bank_transfer' : 'agent_cash_in',
      };
}
