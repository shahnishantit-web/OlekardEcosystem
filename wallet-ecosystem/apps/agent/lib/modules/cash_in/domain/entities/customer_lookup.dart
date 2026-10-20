class CustomerLookup {
  final String walletId;
  final String customerName;
  final String maskedWalletNumber;
  final String accountStatus;

  const CustomerLookup({
    required this.walletId,
    required this.customerName,
    required this.maskedWalletNumber,
    required this.accountStatus,
  });

  bool get isActive => accountStatus == 'active';
}
