/// T118: PaymentIntentStatus enum mirroring server-side values.
enum PaymentIntentStatus {
  pending,
  processing,
  awaitingAction,
  requiresConfirmation,
  succeeded,
  failed,
  cancelled,
  expired,
  refunding,
  partiallyRefunded,
  refunded;

  factory PaymentIntentStatus.fromJson(String value) {
    return switch (value) {
      'pending' => PaymentIntentStatus.pending,
      'processing' => PaymentIntentStatus.processing,
      'awaiting_action' => PaymentIntentStatus.awaitingAction,
      'requires_confirmation' => PaymentIntentStatus.requiresConfirmation,
      'succeeded' => PaymentIntentStatus.succeeded,
      'failed' => PaymentIntentStatus.failed,
      'cancelled' => PaymentIntentStatus.cancelled,
      'expired' => PaymentIntentStatus.expired,
      'refunding' => PaymentIntentStatus.refunding,
      'partially_refunded' => PaymentIntentStatus.partiallyRefunded,
      'refunded' => PaymentIntentStatus.refunded,
      _ => PaymentIntentStatus.processing,
    };
  }

  bool get isTerminal => switch (this) {
        PaymentIntentStatus.succeeded ||
        PaymentIntentStatus.failed ||
        PaymentIntentStatus.cancelled ||
        PaymentIntentStatus.expired ||
        PaymentIntentStatus.refunded =>
          true,
        _ => false,
      };
}
