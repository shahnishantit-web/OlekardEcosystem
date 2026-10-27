import '../../../../core/api/paginated_result.dart';
import '../entities/transaction.dart';
import '../entities/payment_history_filter.dart';
import '../entities/payment_receipt.dart';
import '../entities/refund_request.dart';

abstract interface class TransactionRepository {
  Future<PaginatedResult<PaymentHistoryItem>> fetchHistory(
      PaymentHistoryFilter filter);

  Future<PaymentHistoryItem> fetchDetail(String intentId);

  Future<PaymentReceipt> fetchReceipt(String intentId);

  Future<ReceiptDownloadToken> requestDownloadToken(String intentId);

  Future<void> submitRefund(RefundRequest request);

  Future<void> createSupportTicket(SupportTicketDraft draft);
}
