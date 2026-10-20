import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'package:wallet_agent/core/api/api_client.dart';
import 'package:wallet_agent/modules/cash_in/data/repositories/cash_in_repository_impl.dart';
import 'package:wallet_agent/modules/cash_in/domain/entities/cash_in_result.dart';
import 'package:wallet_agent/modules/cash_in/domain/repositories/cash_in_repository.dart';

final cashInRepositoryProvider = Provider<CashInRepository>((ref) {
  return CashInRepositoryImpl(dio: createDio(null));
});

class CashInResultNotifier extends StateNotifier<CashInResult?> {
  CashInResultNotifier() : super(null);
  void setResult(CashInResult result) => state = result;
  void clear() => state = null;
}

final cashInResultProvider =
    StateNotifierProvider<CashInResultNotifier, CashInResult?>(
  (_) => CashInResultNotifier(),
);
