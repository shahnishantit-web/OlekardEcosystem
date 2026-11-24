import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:wallet_agent/core/api/api_client.dart';
import 'package:wallet_agent/core/session/session_event_bus.dart';
import 'package:wallet_agent/modules/transactions/data/repositories/transaction_repository_impl.dart';
import 'package:wallet_agent/modules/transactions/domain/repositories/transaction_repository.dart';
import 'package:wallet_agent/modules/transactions/infrastructure/payment_cache_store.dart';
import 'package:wallet_agent/modules/auth/presentation/auth_providers.dart';

export 'package:wallet_agent/modules/transactions/application/payment_history_notifier.dart';
export 'package:wallet_agent/modules/transactions/application/payment_detail_notifier.dart';
export 'package:wallet_agent/modules/transactions/application/receipt_access_notifier.dart';

final paymentCacheStoreProvider = Provider<PaymentCacheStore>((ref) {
  final cache = PaymentCacheStore();
  final sub = SessionEventBus.instance.onLogout.listen((_) => cache.clearAll());
  final accountSub =
      SessionEventBus.instance.onAccountSwitch.listen((_) => cache.clearAll());
  ref.onDispose(() {
    sub.cancel();
    accountSub.cancel();
  });
  return cache;
});

final transactionRepositoryProvider = Provider<TransactionRepository>((ref) {
  final cache = ref.watch(paymentCacheStoreProvider);
  final agentId = ref.watch(authNotifierProvider.select((s) => s.agent?.id));
  if (agentId == null) throw StateError('Agent session is not available.');
  return TransactionRepositoryImpl(
      dio: createDio(null), cache: cache, agentId: agentId);
});
