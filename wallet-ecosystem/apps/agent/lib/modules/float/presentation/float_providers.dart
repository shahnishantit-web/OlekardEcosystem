import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_riverpod/legacy.dart';

import 'package:wallet_agent/core/api/api_client.dart';
import 'package:wallet_agent/core/api/api_response.dart';
import 'package:wallet_agent/modules/float/data/repositories/float_repository_impl.dart';
import 'package:wallet_agent/modules/float/domain/entities/float_balance.dart';
import 'package:wallet_agent/modules/float/domain/repositories/float_repository.dart';

final floatRepositoryProvider = Provider<FloatRepository>((ref) {
  return FloatRepositoryImpl(dio: createDio(null));
});

final floatBalanceProvider =
    FutureProvider.autoDispose<FloatBalance>((ref) async {
  return ref.read(floatRepositoryProvider).getBalance();
});

class FloatHistoryFilter {
  final DateTime? fromDate;
  final DateTime? toDate;
  const FloatHistoryFilter({this.fromDate, this.toDate});
  bool get hasRange => fromDate != null || toDate != null;
}

final floatHistoryFilterProvider =
    StateProvider<FloatHistoryFilter>((_) => const FloatHistoryFilter());

final floatTransactionsProvider =
    FutureProvider.autoDispose<PaginatedResult<FloatTransaction>>((ref) async {
  final filter = ref.watch(floatHistoryFilterProvider);
  return ref.read(floatRepositoryProvider).getTransactions(
        fromDate: filter.fromDate,
        toDate: filter.toDate,
      );
});
