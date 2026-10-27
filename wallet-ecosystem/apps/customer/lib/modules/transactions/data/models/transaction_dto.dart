import '../../domain/entities/transaction.dart';
import '../../domain/entities/domain_deep_link.dart';

class TransactionDto {
  static PaymentHistoryItem fromJson(Map<String, dynamic> json) {
    return PaymentHistoryItem(
      id: json['id'] as String,
      referenceNumber:
          (json['reference'] ?? json['reference_number']) as String,
      purposeType: _parsePurposeType(json['purpose_type'] as String? ??
          json['type'] as String? ??
          'unknown'),
      status: _parseStatus(json['status'] as String? ?? 'pending'),
      amountMinorUnits:
          (json['amount'] ?? json['amount_minor_units']) as int? ?? 0,
      feeMinorUnits: json['fee_minor_units'] as int? ?? 0,
      currencyCode: json['currency_code'] as String,
      counterpartyName: json['counterparty_name'] as String?,
      counterpartyWalletNumber: json['counterparty_wallet_number'] as String?,
      description: json['description'] as String?,
      createdAt: DateTime.parse(json['created_at'] as String),
      completedAt: json['completed_at'] != null
          ? DateTime.parse(json['completed_at'] as String)
          : null,
      captureStatus: _parseCaptureStatus(
          json['capture_status'] as String? ?? 'pending'),
      accountingStatus: _parseAccountingStatus(
          json['accounting_status'] as String? ?? 'pending'),
      serviceStatus: _parseServiceStatus(
          json['service_status'] as String? ?? 'pending'),
      refundStatus: json['refund_status'] != null
          ? _parseRefundStatus(json['refund_status'] as String)
          : null,
      refundEligible: json['refund_eligible'] as bool? ?? false,
      domainDeepLink: _parseDomainDeepLink(json['domain_deep_link']),
      receiptAvailable: json['receipt_available'] as bool? ?? false,
      providerName: json['provider_name'] as String?,
      providerReference: json['provider_reference'] as String?,
      direction: _parseDirection(json['direction'] as String? ?? 'debit'),
    );
  }

  static PaymentPurposeType _parsePurposeType(String s) => switch (s) {
        'funding_bank_transfer' || 'funding' =>
          PaymentPurposeType.fundingBankTransfer,
        'funding_agent_cash_in' => PaymentPurposeType.fundingAgentCashIn,
        'wallet_transfer' || 'transfer' => PaymentPurposeType.walletTransfer,
        'withdrawal' => PaymentPurposeType.withdrawal,
        'service_payment' => PaymentPurposeType.servicePayment,
        'bill_payment' => PaymentPurposeType.billPayment,
        'merchant_payment' => PaymentPurposeType.merchantPayment,
        'fx_conversion' => PaymentPurposeType.fxConversion,
        'refund' => PaymentPurposeType.refund,
        'reversal' => PaymentPurposeType.reversal,
        _ => PaymentPurposeType.unknown,
      };

  static PaymentStatus _parseStatus(String s) => switch (s) {
        'pending' => PaymentStatus.pending,
        'processing' => PaymentStatus.processing,
        'completed' => PaymentStatus.completed,
        'failed' => PaymentStatus.failed,
        'reversed' => PaymentStatus.reversed,
        'cancelled' => PaymentStatus.cancelled,
        _ => PaymentStatus.pending,
      };

  static CaptureStatus _parseCaptureStatus(String s) => switch (s) {
        'authorized' => CaptureStatus.authorized,
        'captured' => CaptureStatus.captured,
        'voided' => CaptureStatus.voided,
        'failed' => CaptureStatus.failed,
        _ => CaptureStatus.pending,
      };

  static AccountingStatus _parseAccountingStatus(String s) => switch (s) {
        'posted' => AccountingStatus.posted,
        'rejected' => AccountingStatus.rejected,
        _ => AccountingStatus.pending,
      };

  static ServiceStatus _parseServiceStatus(String s) => switch (s) {
        'submitted' => ServiceStatus.submitted,
        'confirmed' => ServiceStatus.confirmed,
        'failed' => ServiceStatus.failed,
        _ => ServiceStatus.pending,
      };

  static RefundStatus _parseRefundStatus(String s) => switch (s) {
        'requested' => RefundStatus.requested,
        'approved' => RefundStatus.approved,
        'rejected' => RefundStatus.rejected,
        'reversed' => RefundStatus.reversed,
        _ => RefundStatus.pending,
      };

  static PaymentDirection _parseDirection(String s) =>
      s == 'credit' ? PaymentDirection.credit : PaymentDirection.debit;

  static DomainDeepLink? _parseDomainDeepLink(dynamic raw) {
    if (raw == null) return null;
    if (raw is String) return AvailableLink(url: raw);
    if (raw is Map<String, dynamic>) {
      final status = raw['status'] as String?;
      if (status == 'available' || raw.containsKey('url')) {
        return AvailableLink(
          url: raw['url'] as String,
          label: raw['label'] as String?,
        );
      }
      return UnavailableLink(
          reason: raw['reason'] as String? ?? 'unavailable');
    }
    return null;
  }
}
