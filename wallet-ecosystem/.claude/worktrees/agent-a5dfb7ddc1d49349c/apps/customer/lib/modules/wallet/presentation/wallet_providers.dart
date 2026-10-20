import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../domain/entities/wallet.dart';
import '../domain/repositories/wallet_repository.dart';
import '../data/repositories/wallet_repository_impl.dart';
import '../../../core/di/providers.dart';

final walletRepositoryProvider = Provider<WalletRepository>((ref) {
  return WalletRepositoryImpl(apiClient: ref.watch(apiClientProvider));
});

final walletProvider = FutureProvider.autoDispose<Wallet>((ref) async {
  return ref.watch(walletRepositoryProvider).getMyWallet();
});

final walletLimitsProvider = FutureProvider.autoDispose<WalletLimits>((ref) async {
  return ref.watch(walletRepositoryProvider).getWalletLimits();
});
