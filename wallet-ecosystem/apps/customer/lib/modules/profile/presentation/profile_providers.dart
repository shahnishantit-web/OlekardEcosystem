import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../domain/entities/profile.dart';
import '../domain/repositories/profile_repository.dart';
import '../data/repositories/profile_repository_impl.dart';
import '../../../core/di/providers.dart';

final profileRepositoryProvider = Provider<ProfileRepository>((ref) {
  return ProfileRepositoryImpl(apiClient: ref.watch(apiClientProvider));
});

final profileProvider =
    FutureProvider.autoDispose<CustomerProfile>((ref) async {
  return ref.watch(profileRepositoryProvider).getProfile();
});
