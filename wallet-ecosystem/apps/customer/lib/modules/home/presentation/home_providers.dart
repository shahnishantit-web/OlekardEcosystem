import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_riverpod/legacy.dart' show StateProvider;
import '../domain/entities/home_summary.dart';
import '../../wallet/presentation/wallet_providers.dart';
import '../../transactions/presentation/transaction_providers.dart';
import '../../transactions/domain/entities/transaction.dart';

final balanceVisibleProvider = StateProvider<bool>((_) => true);

final homeSummaryProvider =
    FutureProvider.autoDispose<HomeSummary>((ref) async {
  final wallet = await ref.watch(walletProvider.future);
  final historyState = await ref.watch(paymentHistoryProvider('me').future);
  final recentTransactions = historyState is PaymentHistoryLoaded
      ? historyState.items.take(5).toList()
      : <Transaction>[];
  return HomeSummary(
    primaryWallet: wallet,
    recentTransactions: recentTransactions,
    unreadNotificationCount: 0,
  );
});
