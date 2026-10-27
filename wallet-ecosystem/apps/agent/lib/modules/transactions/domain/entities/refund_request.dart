class RefundRequest {
  final String intentId;
  final String reason;
  final int? amountMinorUnits;

  const RefundRequest({
    required this.intentId,
    required this.reason,
    this.amountMinorUnits,
  });
}

class SupportTicketDraft {
  final String paymentIntentId;
  final String subject;
  final String? description;
  final List<String> attachmentPaths;

  const SupportTicketDraft({
    required this.paymentIntentId,
    required this.subject,
    this.description,
    this.attachmentPaths = const [],
  });
}
