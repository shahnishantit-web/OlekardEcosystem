import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../domain/entities/payment_receipt.dart';
import '../domain/repositories/transaction_repository.dart';
import '../data/repositories/transaction_repository_impl.dart';
import '../presentation/transaction_repository_provider.dart';
import '../../../../core/session/session_event_bus.dart';

// ─── State ────────────────────────────────────────────────────────────────────

sealed class ReceiptAccessState {
  const ReceiptAccessState();
}

final class ReceiptAccessLoading extends ReceiptAccessState {
  const ReceiptAccessLoading();
}

final class ReceiptReady extends ReceiptAccessState {
  final PaymentReceipt receipt;
  const ReceiptReady({required this.receipt});
}

final class ReceiptDownloadRequested extends ReceiptAccessState {
  final PaymentReceipt receipt;
  const ReceiptDownloadRequested({required this.receipt});
}

/// Token obtained. Widget MUST call url_launcher immediately then call
/// [ReceiptAccessNotifier.onDownloadLaunched]. The [downloadUrl] is ephemeral
/// and must never be stored, logged, or included in analytics.
final class ReceiptDownloadReady extends ReceiptAccessState {
  final PaymentReceipt receipt;
  final String downloadUrl;
  final DateTime expiresAt;

  const ReceiptDownloadReady({
    required this.receipt,
    required this.downloadUrl,
    required this.expiresAt,
  });
}

final class ReceiptDownloadError extends ReceiptAccessState {
  final PaymentReceipt receipt;
  final String message;
  const ReceiptDownloadError({required this.receipt, required this.message});
}

final class ReceiptAccessDenied extends ReceiptAccessState {
  final String reason;
  const ReceiptAccessDenied({required this.reason});
}

final class ReceiptAccessError extends ReceiptAccessState {
  final String message;
  const ReceiptAccessError({required this.message});
}

// ─── Notifier ─────────────────────────────────────────────────────────────────

class ReceiptAccessNotifier
    extends AsyncNotifier<ReceiptAccessState> {
  final String intentId;

  ReceiptAccessNotifier(this.intentId);

  late TransactionRepository _repo;
  // Incremented on account-switch; guards against applying stale async results.
  int _requestGeneration = 0;

  @override
  Future<ReceiptAccessState> build() async {
    _repo = ref.watch(transactionRepositoryProvider);

    // On account-switch: discard any in-flight download and reset to loading.
    final sub = SessionEventBus.instance.onAccountSwitch.listen((_) {
      _requestGeneration++;
      state = const AsyncValue.data(ReceiptAccessLoading());
    });
    ref.onDispose(sub.cancel);

    try {
      final receipt = await _repo.fetchReceipt(intentId);
      return ReceiptReady(receipt: receipt);
    } on AccessDeniedException catch (e) {
      return ReceiptAccessDenied(reason: e.message);
    } catch (e) {
      return ReceiptAccessError(message: e.toString());
    }
  }

  Future<void> requestDownload() async {
    final current = state.value;
    PaymentReceipt? receipt;
    if (current is ReceiptReady) receipt = current.receipt;
    if (current is ReceiptDownloadError) receipt = current.receipt;
    if (receipt == null) return;

    final generation = ++_requestGeneration;
    state = AsyncValue.data(ReceiptDownloadRequested(receipt: receipt));
    try {
      final token = await _repo.requestDownloadToken(intentId);
      if (_requestGeneration != generation) return; // account-switched mid-flight
      // downloadUrl placed in state only — must never be written to storage.
      state = AsyncValue.data(ReceiptDownloadReady(
        receipt: receipt,
        downloadUrl: token.downloadUrl,
        expiresAt: token.expiresAt,
      ));
    } on AccessDeniedException catch (e) {
      if (_requestGeneration != generation) return;
      state = AsyncValue.data(ReceiptAccessDenied(reason: e.message));
    } catch (e) {
      if (_requestGeneration != generation) return;
      state = AsyncValue.data(
          ReceiptDownloadError(receipt: receipt, message: e.toString()));
    }
  }

  /// Called by the widget after url_launcher.launchUrl() to clear the
  /// ephemeral downloadUrl from state.
  void onDownloadLaunched() {
    final current = state.value;
    if (current is! ReceiptDownloadReady) return;
    state = AsyncValue.data(ReceiptReady(receipt: current.receipt));
  }
}

final receiptAccessProvider = AsyncNotifierProvider.family<
    ReceiptAccessNotifier, ReceiptAccessState, String>(
  ReceiptAccessNotifier.new,
);
