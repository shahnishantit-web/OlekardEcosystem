import 'package:wallet_agent/modules/transactions/domain/entities/agent_transaction.dart';

class PaymentStatusRows {
  final String captureStatus;
  final String accountingStatus;
  final String serviceStatus;
  final String? refundStatus;

  const PaymentStatusRows({
    required this.captureStatus,
    required this.accountingStatus,
    required this.serviceStatus,
    this.refundStatus,
  });

  factory PaymentStatusRows.fromItem(PaymentHistoryItem item) {
    return PaymentStatusRows(
      captureStatus: _captureLabel(item.captureStatus),
      accountingStatus: _accountingLabel(item.accountingStatus),
      serviceStatus: _serviceLabel(item.serviceStatus),
      refundStatus:
          item.refundStatus != null ? _refundLabel(item.refundStatus!) : null,
    );
  }

  static String _captureLabel(CaptureStatus s) => switch (s) {
        CaptureStatus.pending => 'Capture Pending',
        CaptureStatus.authorized => 'Authorized',
        CaptureStatus.captured => 'Captured',
        CaptureStatus.voided => 'Voided',
        CaptureStatus.failed => 'Capture Failed',
      };

  static String _accountingLabel(AccountingStatus s) => switch (s) {
        AccountingStatus.pending => 'Ledger Pending',
        AccountingStatus.posted => 'Posted',
        AccountingStatus.rejected => 'Ledger Rejected',
      };

  static String _serviceLabel(ServiceStatus s) => switch (s) {
        ServiceStatus.pending => 'Pending Fulfilment',
        ServiceStatus.submitted => 'Submitted',
        ServiceStatus.confirmed => 'Fulfilled',
        ServiceStatus.failed => 'Fulfilment Failed',
      };

  static String _refundLabel(RefundStatus s) => switch (s) {
        RefundStatus.requested => 'Refund Requested',
        RefundStatus.pending => 'Refund Pending',
        RefundStatus.approved => 'Refund Approved',
        RefundStatus.rejected => 'Refund Rejected',
        RefundStatus.reversed => 'Refunded',
      };
}
