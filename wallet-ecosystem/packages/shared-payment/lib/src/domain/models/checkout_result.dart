class CheckoutResult {
  final bool succeeded;
  final String? paymentIntentId;
  final String? errorCode;

  const CheckoutResult({
    required this.succeeded,
    this.paymentIntentId,
    this.errorCode,
  });
}
