import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../domain/entities/service_provider.dart';
import '../domain/repositories/service_repository.dart';
import '../data/repositories/service_repository_impl.dart';
import '../../../../core/api/paginated_result.dart';
import '../../../core/di/providers.dart';

final serviceRepositoryProvider = Provider<ServiceRepository>((ref) {
  return ServiceRepositoryImpl(apiClient: ref.watch(apiClientProvider));
});

final serviceCategoriesProvider = FutureProvider.autoDispose<List<ServiceCategory>>((ref) async {
  return ref.watch(serviceRepositoryProvider).getCategories();
});

final serviceProvidersProvider =
    FutureProvider.autoDispose.family<List<ServiceProvider>, String>((ref, categoryId) async {
  return ref.watch(serviceRepositoryProvider).getProviders(categoryId);
});

final serviceProviderDetailProvider =
    FutureProvider.autoDispose.family<ServiceProvider, String>((ref, providerId) async {
  return ref.watch(serviceRepositoryProvider).getProvider(providerId);
});

final servicePaymentHistoryProvider =
    FutureProvider.autoDispose.family<PaginatedResult<ServicePayment>, int>((ref, page) async {
  return ref.watch(serviceRepositoryProvider).getServiceHistory(page);
});
