import 'payment_status.dart';

/// T116: PaymentIntent domain model.
class PaymentIntent {
  final String id;
  final String purposeType;
  final String purposeReference;
  final String organisationId;
  final String payerActorId;
  final String currencyCode;
  final int amountMinor;
  final PaymentIntentStatus status;
  final String idempotencyKey;
  final String correlationId;
  final DateTime createdAt;
  final DateTime? expiresAt;

  const PaymentIntent({
    required this.id,
    required this.purposeType,
    required this.purposeReference,
    required this.organisationId,
    required this.payerActorId,
    required this.currencyCode,
    required this.amountMinor,
    required this.status,
    required this.idempotencyKey,
    required this.correlationId,
    required this.createdAt,
    this.expiresAt,
  });

  factory PaymentIntent.fromJson(Map<String, dynamic> json) {
    return PaymentIntent(
      id: json['id'] as String,
      purposeType: json['purpose_type'] as String,
      purposeReference: json['purpose_reference'] as String,
      organisationId: json['organisation_id'] as String,
      payerActorId: json['payer_actor_id'] as String,
      currencyCode: json['currency_code'] as String,
      amountMinor: json['amount_minor'] as int,
      status: PaymentIntentStatus.fromJson(json['status'] as String),
      idempotencyKey: json['idempotency_key'] as String,
      correlationId: json['correlation_id'] as String,
      createdAt: DateTime.parse(json['created_at'] as String),
      expiresAt: json['expires_at'] != null
          ? DateTime.parse(json['expires_at'] as String)
          : null,
    );
  }
}
