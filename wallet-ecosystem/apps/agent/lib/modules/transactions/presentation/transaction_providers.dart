import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:wallet_agent/core/api/api_client.dart';
import 'package:wallet_agent/core/session/session_event_bus.dart';
import 'package:wallet_agent/modules/transactions/data/repositories/transaction_repository_impl.dart';
import 'package:wallet_agent/modules/transactions/domain/repositories/transaction_repository.dart';
import 'package:wallet_agent/modules/transactions/infrastructure/payment_cache_store.dart';

export 'package:wallet_agent/modules/transactions/application/payment_history_notifier.dart';
export 'package:wallet_agent/modules/transactions/application/payment_detail_notifier.dart';
export 'package:wallet_agent/modules/transactions/application/receipt_access_notifier.dart';

final paymentCacheStoreProvider = Provider<PaymentCacheStore>((ref) {
  final cache = PaymentCacheStore();
  final sub = SessionEventBus.instance.onLogout
      .listen((_) => cache.clearAll());
  final accountSub = SessionEventBus.instance.onAccountSwitch
      .listen((_) => cache.clearAll());
  ref.onDispose(() {
    sub.cancel();
    accountSub.cancel();
  });
  return cache;
});

final transactionRepositoryProvider = Provider<TransactionRepository>((ref) {
  final cache = ref.watch(paymentCacheStoreProvider);
  return TransactionRepositoryImpl(dio: createDio(null), cache: cache);
});
