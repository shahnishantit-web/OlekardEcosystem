import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../domain/repositories/funding_repository.dart';
import '../data/repositories/funding_repository_impl.dart';
import '../../../core/di/providers.dart';

final fundingRepositoryProvider = Provider<FundingRepository>((ref) {
  return FundingRepositoryImpl(apiClient: ref.watch(apiClientProvider));
});
