import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../domain/repositories/withdrawal_repository.dart';
import '../data/repositories/withdrawal_repository_impl.dart';
import '../../../core/di/providers.dart';

final withdrawalRepositoryProvider = Provider<WithdrawalRepository>((ref) {
  return WithdrawalRepositoryImpl(apiClient: ref.watch(apiClientProvider));
});
