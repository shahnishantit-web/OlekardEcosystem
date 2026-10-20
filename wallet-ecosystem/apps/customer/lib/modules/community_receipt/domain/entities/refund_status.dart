enum RefundStatus {
  pending,
  approved,
  settled,
  rejected,
  failed;

  String get label => switch (this) {
        RefundStatus.pending  => 'Pending',
        RefundStatus.approved => 'Approved',
        RefundStatus.settled  => 'Settled',
        RefundStatus.rejected => 'Rejected',
        RefundStatus.failed   => 'Failed',
      };
}

class RefundRequest {
  final String id;
  final String referenceNumber;
  final int amountMinor;
  final String currency;
  final RefundStatus status;
  final String reason;
  final DateTime requestedAt;

  const RefundRequest({
    required this.id,
    required this.referenceNumber,
    required this.amountMinor,
    required this.currency,
    required this.status,
    required this.reason,
    required this.requestedAt,
  });

  factory RefundRequest.fromJson(Map<String, dynamic> json) {
    return RefundRequest(
      id: json['id'] as String,
      referenceNumber: json['reference_number'] as String? ?? json['id'] as String,
      amountMinor: json['amount_minor'] as int,
      currency: json['currency'] as String,
      status: RefundStatus.values.firstWhere(
        (s) => s.name == (json['status'] as String),
        orElse: () => RefundStatus.pending,
      ),
      reason: json['reason'] as String,
      requestedAt: DateTime.parse(json['requested_at'] as String),
    );
  }
}

class RefundState {
  final List<RefundRequest> requests;
  final bool isEligible;
  final String policyText;

  const RefundState({
    required this.requests,
    required this.isEligible,
    required this.policyText,
  });
}
