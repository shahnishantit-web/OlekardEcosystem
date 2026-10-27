import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import '../../../core/api/api_client.dart';
import '../../../core/di/providers.dart';
import '../../../core/session/session_event_bus.dart';
import '../data/repositories/transaction_repository_impl.dart';
import '../domain/repositories/transaction_repository.dart';
import '../infrastructure/payment_cache_store.dart';

final paymentCacheStoreProvider = Provider<PaymentCacheStore>((ref) {
  final cache = const PaymentCacheStore();
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
  final apiClient = ref.watch(apiClientProvider);
  final cache = ref.watch(paymentCacheStoreProvider);
  return TransactionRepositoryImpl(apiClient: apiClient, cache: cache);
});
