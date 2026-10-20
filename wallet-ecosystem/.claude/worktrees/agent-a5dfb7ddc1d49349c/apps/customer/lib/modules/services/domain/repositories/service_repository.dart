import '../../../../core/api/paginated_result.dart';
import '../entities/service_provider.dart';

abstract interface class ServiceRepository {
  Future<List<ServiceCategory>> getCategories();
  Future<List<ServiceProvider>> getProviders(String categoryId);
  Future<ServiceProvider> getProvider(String providerId);
  Future<ServicePayment> purchaseService(ServicePurchaseRequestDto request);
  Future<PaginatedResult<ServicePayment>> getServiceHistory(int page);
}
