import 'package:wallet_agent/core/api/api_response.dart';
import 'package:wallet_agent/modules/transactions/domain/entities/agent_transaction.dart';
import 'package:wallet_agent/modules/transactions/domain/entities/payment_history_filter.dart';
import 'package:wallet_agent/modules/transactions/domain/entities/payment_receipt.dart';
import 'package:wallet_agent/modules/transactions/domain/entities/refund_request.dart';

abstract class TransactionRepository {
  Future<PaginatedResult<PaymentHistoryItem>> fetchHistory(
      PaymentHistoryFilter filter);

  Future<PaymentHistoryItem> fetchDetail(String intentId);

  Future<PaymentReceipt> fetchReceipt(String intentId);

  Future<ReceiptDownloadToken> requestDownloadToken(String intentId);

  Future<void> submitRefund(RefundRequest request);

  Future<void> createSupportTicket(SupportTicketDraft draft);
}
