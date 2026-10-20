class PickupCodeLookup {
  final String pickupCode;
  final String customerName;
  final String maskedWalletNumber;
  final String walletId;
  final int amountMinorUnits;
  final String currencyCode;
  final DateTime expiresAt;

  const PickupCodeLookup({
    required this.pickupCode,
    required this.customerName,
    required this.maskedWalletNumber,
    required this.walletId,
    required this.amountMinorUnits,
    required this.currencyCode,
    required this.expiresAt,
  });

  bool get isExpired => DateTime.now().isAfter(expiresAt);
}

class PickupCodeExpiredException implements Exception {
  final String code;
  const PickupCodeExpiredException(this.code);
  @override
  String toString() => 'Pickup code $code has expired.';
}

class PickupCodeRedeemedException implements Exception {
  final String code;
  const PickupCodeRedeemedException(this.code);
  @override
  String toString() => 'Pickup code $code has already been redeemed.';
}
