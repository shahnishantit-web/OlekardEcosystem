import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_riverpod/legacy.dart';

import 'package:wallet_agent/core/api/api_client.dart';
import 'package:wallet_agent/core/api/api_response.dart';
import 'package:wallet_agent/modules/commissions/data/repositories/commission_repository_impl.dart';
import 'package:wallet_agent/modules/commissions/domain/entities/commission_balance.dart';
import 'package:wallet_agent/modules/commissions/domain/repositories/commission_repository.dart';

final commissionRepositoryProvider = Provider<CommissionRepository>((ref) {
  return CommissionRepositoryImpl(dio: createDio(null));
});

final commissionBalanceProvider =
    FutureProvider.autoDispose<CommissionBalance>((ref) async {
  return ref.read(commissionRepositoryProvider).getBalance();
});

class CommissionFilter {
  final String? type;
  final DateTime? fromDate;
  final DateTime? toDate;

  const CommissionFilter({this.type, this.fromDate, this.toDate});

  CommissionFilter copyWith({
    Object? type = _sentinel,
    Object? fromDate = _sentinel,
    Object? toDate = _sentinel,
  }) {
    return CommissionFilter(
      type: type == _sentinel ? this.type : type as String?,
      fromDate: fromDate == _sentinel ? this.fromDate : fromDate as DateTime?,
      toDate: toDate == _sentinel ? this.toDate : toDate as DateTime?,
    );
  }
}

const _sentinel = Object();

final commissionFilterProvider =
    StateProvider<CommissionFilter>((_) => const CommissionFilter());

final commissionEntriesProvider =
    FutureProvider.autoDispose<PaginatedResult<CommissionEntry>>((ref) async {
  final filter = ref.watch(commissionFilterProvider);
  return ref.read(commissionRepositoryProvider).getEntries(
        type: filter.type,
        fromDate: filter.fromDate,
        toDate: filter.toDate,
      );
});
