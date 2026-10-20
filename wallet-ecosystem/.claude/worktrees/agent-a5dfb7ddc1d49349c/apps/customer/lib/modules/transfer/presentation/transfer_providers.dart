import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../domain/repositories/transfer_repository.dart';
import '../data/repositories/transfer_repository_impl.dart';
import '../../../core/di/providers.dart';

final transferRepositoryProvider = Provider<TransferRepository>((ref) {
  return TransferRepositoryImpl(apiClient: ref.watch(apiClientProvider));
});
