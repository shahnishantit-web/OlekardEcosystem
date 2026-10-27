import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_riverpod/legacy.dart';

import 'package:wallet_agent/core/api/api_client.dart';
import 'package:wallet_agent/modules/cash_out/data/repositories/cash_out_repository_impl.dart';
import 'package:wallet_agent/modules/cash_out/domain/entities/cash_out_result.dart';
import 'package:wallet_agent/modules/cash_out/domain/repositories/cash_out_repository.dart';

final cashOutRepositoryProvider = Provider<CashOutRepository>((ref) {
  return CashOutRepositoryImpl(dio: createDio(null));
});

class CashOutResultNotifier extends StateNotifier<CashOutResult?> {
  CashOutResultNotifier() : super(null);
  void setResult(CashOutResult result) => state = result;
  void clear() => state = null;
}

final cashOutResultProvider =
    StateNotifierProvider<CashOutResultNotifier, CashOutResult?>(
  (_) => CashOutResultNotifier(),
);
