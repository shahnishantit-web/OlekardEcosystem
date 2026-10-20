import 'package:dio/dio.dart';
import '../../../../core/api/api_client.dart';
import '../../../../core/api/paginated_result.dart';
import '../../domain/entities/service_provider.dart';
import '../../domain/repositories/service_repository.dart';

class ServiceRepositoryImpl implements ServiceRepository {
  final Dio _dio;

  ServiceRepositoryImpl({required ApiClient apiClient}) : _dio = apiClient.dio;

  @override
  Future<List<ServiceCategory>> getCategories() async {
    final res = await _dio.get('/v1/customer/services/categories');
    return (res.data as List)
        .map((e) => _categoryFromJson(e as Map<String, dynamic>))
        .toList();
  }

  @override
  Future<List<ServiceProvider>> getProviders(String categoryId) async {
    final res = await _dio.get('/v1/customer/services/categories/$categoryId/providers');
    return (res.data as List)
        .map((e) => _providerFromJson(e as Map<String, dynamic>))
        .toList();
  }

  @override
  Future<ServiceProvider> getProvider(String providerId) async {
    final res = await _dio.get('/v1/customer/services/providers/$providerId');
    return _providerFromJson(res.data as Map<String, dynamic>);
  }

  @override
  Future<ServicePayment> purchaseService(ServicePurchaseRequestDto request) async {
    final res = await _dio.post(
      '/v1/customer/services/purchase',
      data: request.toJson(),
      options: Options(headers: {'Idempotency-Key': request.idempotencyKey}),
    );
    final d = (res.statusCode == 409 ? res.data['data'] : res.data) as Map<String, dynamic>;
    return _paymentFromJson(d);
  }

  @override
  Future<PaginatedResult<ServicePayment>> getServiceHistory(int page) async {
    final res = await _dio.get('/v1/customer/services/history', queryParameters: {'page': page});
    return PaginatedResult.fromJson(res.data as Map<String, dynamic>, _paymentFromJson);
  }

  ServiceCategory _categoryFromJson(Map<String, dynamic> j) => ServiceCategory(
        id: j['id'] as String,
        name: j['name'] as String,
        iconUrl: j['icon_url'] as String? ?? '',
        description: j['description'] as String? ?? '',
        providerCount: j['provider_count'] as int? ?? 0,
      );

  ServiceProvider _providerFromJson(Map<String, dynamic> j) => ServiceProvider(
        id: j['id'] as String,
        categoryId: j['category_id'] as String,
        name: j['name'] as String,
        logoUrl: j['logo_url'] as String? ?? '',
        description: j['description'] as String? ?? '',
        requiredFields: (j['required_fields'] as List? ?? [])
            .map((f) => _fieldFromJson(f as Map<String, dynamic>))
            .toList(),
        feeMinorUnits: j['fee_minor_units'] as int? ?? 0,
        currencyCode: j['currency_code'] as String? ?? 'KES',
        isActive: j['is_active'] as bool? ?? true,
      );

  ServiceField _fieldFromJson(Map<String, dynamic> j) => ServiceField(
        key: j['key'] as String,
        label: j['label'] as String,
        type: ServiceFieldType.values.firstWhere(
          (e) => e.name == (j['type'] as String? ?? 'text'),
          orElse: () => ServiceFieldType.text,
        ),
        required: j['required'] as bool? ?? true,
        hint: j['hint'] as String?,
        options: (j['options'] as List?)?.cast<String>(),
      );

  ServicePayment _paymentFromJson(Map<String, dynamic> j) => ServicePayment(
        id: j['id'] as String,
        referenceNumber: j['reference_number'] as String,
        providerId: j['provider_id'] as String,
        providerName: j['provider_name'] as String,
        amountMinorUnits: j['amount_minor_units'] as int,
        feeMinorUnits: j['fee_minor_units'] as int? ?? 0,
        currencyCode: j['currency_code'] as String,
        status: ServicePaymentStatus.completed,
        fieldValues: (j['field_values'] as Map<String, dynamic>? ?? {})
            .map((k, v) => MapEntry(k, v.toString())),
        createdAt: DateTime.parse(j['created_at'] as String),
      );
}
