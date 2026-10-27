import 'package:wallet_agent/modules/transactions/domain/entities/payment_history_filter.dart';
import 'package:wallet_agent/modules/transactions/domain/entities/agent_transaction.dart';

class PaymentHistoryFilterDto {
  static Map<String, String> toQueryParams(PaymentHistoryFilter filter) {
    final params = <String, String>{
      'page': filter.page.toString(),
      'per_page': filter.perPage.toString(),
    };
    if (filter.purposeType != null) {
      params['purpose_type'] = _purposeTypeToString(filter.purposeType!);
    }
    if (filter.status != null) {
      params['status'] = _statusToString(filter.status!);
    }
    if (filter.dateFrom != null) {
      params['date_from'] = filter.dateFrom!.toIso8601String();
    }
    if (filter.dateTo != null) {
      params['date_to'] = filter.dateTo!.toIso8601String();
    }
    if (filter.currencyCode != null) {
      params['currency_code'] = filter.currencyCode!;
    }
    return params;
  }

  static String _purposeTypeToString(PaymentPurposeType t) => switch (t) {
        PaymentPurposeType.fundingBankTransfer => 'funding_bank_transfer',
        PaymentPurposeType.fundingAgentCashIn => 'funding_agent_cash_in',
        PaymentPurposeType.walletTransfer => 'wallet_transfer',
        PaymentPurposeType.withdrawal => 'withdrawal',
        PaymentPurposeType.servicePayment => 'service_payment',
        PaymentPurposeType.billPayment => 'bill_payment',
        PaymentPurposeType.merchantPayment => 'merchant_payment',
        PaymentPurposeType.fxConversion => 'fx_conversion',
        PaymentPurposeType.refund => 'refund',
        PaymentPurposeType.reversal => 'reversal',
        PaymentPurposeType.unknown => 'unknown',
      };

  static String _statusToString(PaymentStatus s) => switch (s) {
        PaymentStatus.pending => 'pending',
        PaymentStatus.processing => 'processing',
        PaymentStatus.completed => 'completed',
        PaymentStatus.failed => 'failed',
        PaymentStatus.reversed => 'reversed',
        PaymentStatus.cancelled => 'cancelled',
      };
}
