import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_riverpod/legacy.dart' show StateProvider;
import '../domain/entities/home_summary.dart';
import '../../wallet/presentation/wallet_providers.dart';
import '../../transactions/presentation/transaction_providers.dart';

final balanceVisibleProvider = StateProvider<bool>((_) => true);

final homeSummaryProvider =
    FutureProvider.autoDispose<HomeSummary>((ref) async {
  final wallet = await ref.watch(walletProvider.future);
  final txPage = await ref.watch(transactionListProvider(1).future);
  return HomeSummary(
    primaryWallet: wallet,
    recentTransactions: txPage.data.take(5).toList(),
    unreadNotificationCount: 0,
  );
});
