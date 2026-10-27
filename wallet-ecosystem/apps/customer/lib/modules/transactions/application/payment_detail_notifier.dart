import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../domain/entities/transaction.dart';
import '../domain/entities/domain_deep_link.dart';
import '../domain/entities/payment_status_rows.dart';
import '../domain/entities/refund_request.dart';
import '../domain/repositories/transaction_repository.dart';
import '../presentation/transaction_repository_provider.dart';

// ─── State ────────────────────────────────────────────────────────────────────

sealed class PaymentDetailState {
  const PaymentDetailState();
}

final class PaymentDetailLoading extends PaymentDetailState {
  const PaymentDetailLoading();
}

final class PaymentDetailLoaded extends PaymentDetailState {
  final PaymentHistoryItem item;
  final PaymentStatusRows statusRows;
  final DomainDeepLink? deepLink;

  const PaymentDetailLoaded({
    required this.item,
    required this.statusRows,
    this.deepLink,
  });
}

final class PaymentDetailRefundConfirm extends PaymentDetailState {
  final PaymentHistoryItem item;
  const PaymentDetailRefundConfirm({required this.item});
}

final class PaymentDetailRefundSubmitting extends PaymentDetailState {
  final PaymentHistoryItem item;
  const PaymentDetailRefundSubmitting({required this.item});
}

final class PaymentDetailRefundSubmitted extends PaymentDetailState {
  final PaymentHistoryItem item;
  const PaymentDetailRefundSubmitted({required this.item});
}

final class PaymentDetailSupportDraft extends PaymentDetailState {
  final PaymentHistoryItem item;
  const PaymentDetailSupportDraft({required this.item});
}

final class PaymentDetailError extends PaymentDetailState {
  final String message;
  const PaymentDetailError({required this.message});
}

// ─── Notifier ─────────────────────────────────────────────────────────────────

class PaymentDetailNotifier
    extends AsyncNotifier<PaymentDetailState> {
  final String intentId;

  PaymentDetailNotifier(this.intentId);

  late TransactionRepository _repo;

  @override
  Future<PaymentDetailState> build() async {
    _repo = ref.watch(transactionRepositoryProvider);
    try {
      final item = await _repo.fetchDetail(intentId);
      return PaymentDetailLoaded(
        item: item,
        statusRows: PaymentStatusRows.fromItem(item),
        deepLink: item.domainDeepLink,
      );
    } catch (e) {
      return PaymentDetailError(message: e.toString());
    }
  }

  Future<void> initiateRefund() async {
    final current = state.value;
    if (current is! PaymentDetailLoaded) return;
    if (!current.item.refundEligible) return;
    state = AsyncValue.data(PaymentDetailRefundConfirm(item: current.item));
  }

  Future<void> submitRefund({
    required String reason,
    int? amountMinor,
  }) async {
    final current = state.value;
    if (current is! PaymentDetailRefundConfirm) return;
    final item = current.item;

    state = AsyncValue.data(PaymentDetailRefundSubmitting(item: item));
    try {
      await _repo.submitRefund(RefundRequest(
        intentId: item.id,
        reason: reason,
        amountMinorUnits: amountMinor,
      ));
      state = AsyncValue.data(PaymentDetailRefundSubmitted(item: item));
    } catch (e) {
      state = AsyncValue.data(PaymentDetailError(message: e.toString()));
    }
  }

  Future<void> openSupportDraft() async {
    final current = state.value;
    if (current is! PaymentDetailLoaded) return;
    state = AsyncValue.data(PaymentDetailSupportDraft(item: current.item));
  }

  Future<void> cancel() async {
    final current = state.value;
    PaymentHistoryItem? item;
    if (current is PaymentDetailRefundConfirm) item = current.item;
    if (current is PaymentDetailSupportDraft) item = current.item;
    if (item == null) return;
    state = AsyncValue.data(PaymentDetailLoaded(
      item: item,
      statusRows: PaymentStatusRows.fromItem(item),
      deepLink: item.domainDeepLink,
    ));
  }
}

final paymentDetailProvider = AsyncNotifierProvider.family<
    PaymentDetailNotifier, PaymentDetailState, String>(
  PaymentDetailNotifier.new,
);
