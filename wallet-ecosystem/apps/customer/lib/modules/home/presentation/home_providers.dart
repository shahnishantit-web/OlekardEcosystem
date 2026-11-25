import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_riverpod/legacy.dart' show StateProvider;
import 'package:dio/dio.dart';
import '../../../../core/di/providers.dart';
import '../domain/entities/home_summary.dart';
import '../../wallet/presentation/wallet_providers.dart';
import '../../transactions/presentation/transaction_providers.dart';
import '../../transactions/domain/entities/transaction.dart';

final balanceVisibleProvider = StateProvider<bool>((_) => true);

final homeUpcomingEventsProvider =
    FutureProvider.autoDispose<List<Map<String, dynamic>>>((ref) async {
  final dio = ref.watch(apiClientProvider).dio;
  final response = await dio.get<Object?>('/v1/events/customer/catalogue');
  final body = response.data as Map<String, dynamic>;
  final rows = body['data'] as List<dynamic>? ?? const [];
  return rows.whereType<Map<String, dynamic>>().take(2).toList(growable: false);
});

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
