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
    final catalog = await _catalog();
    final groups = <String, int>{};
    for (final service in catalog) {
      final category = service['category'] as String;
      groups[category] = (groups[category] ?? 0) + 1;
    }
    return groups.entries
        .map((entry) => ServiceCategory(
            id: entry.key,
            name: entry.key.replaceAll('_', ' '),
            iconUrl: '',
            description: '',
            providerCount: entry.value))
        .toList();
  }

  @override
  Future<List<ServiceProvider>> getProviders(String categoryId) async {
    return (await _catalog(category: categoryId))
        .map(_providerFromJson)
        .toList();
  }

  @override
  Future<ServiceProvider> getProvider(String providerId) async {
    final res = await _dio.get('/api/v1/services/$providerId');
    return _providerFromJson(res.data['data'] as Map<String, dynamic>);
  }

  @override
  Future<ServicePayment> purchaseService(
      ServicePurchaseRequestDto request) async {
    final res = await _dio.post(
      '/v1/customer/services/purchase',
      data: request.toJson(),
      options: Options(headers: {'Idempotency-Key': request.idempotencyKey}),
    );
    final d = (res.statusCode == 409 ? res.data['data'] : res.data)
        as Map<String, dynamic>;
    return _paymentFromJson(d);
  }

  @override
  Future<PaginatedResult<ServicePayment>> getServiceHistory(int page) async {
    final res = await _dio
        .get('/api/v1/services/payments', queryParameters: {'page': page});
    return PaginatedResult.fromJson(
        res.data as Map<String, dynamic>, _paymentFromJson);
  }

  ServiceProvider _providerFromJson(Map<String, dynamic> j) => ServiceProvider(
        id: j['id'] as String,
        categoryId: j['category'] as String,
        name: j['name'] as String,
        logoUrl: j['logo_url'] as String? ?? '',
        description: j['description'] as String? ?? '',
        requiredFields: (j['required_fields'] as List? ?? [])
            .map((f) => f is String
                ? ServiceField(
                    key: f,
                    label: f.replaceAll('_', ' '),
                    type: ServiceFieldType.text,
                    required: true)
                : _fieldFromJson(f as Map<String, dynamic>))
            .toList(),
        feeMinorUnits: j['fee_minor_units'] as int? ?? 0,
        currencyCode: j['currency_code'] as String,
        isActive: j['status'] == 'active',
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
        referenceNumber: j['id'] as String,
        providerId: j['service_id'] as String,
        providerName: j['service_name'] as String? ?? 'Service payment',
        amountMinorUnits: j['amount'] as int,
        feeMinorUnits: j['fee_amount'] as int,
        currencyCode: j['currency_code'] as String,
        status: ServicePaymentStatus.values.firstWhere(
            (value) => value.name == j['status'],
            orElse: () => ServicePaymentStatus.processing),
        fieldValues: (j['field_values'] as Map<String, dynamic>? ?? {})
            .map((k, v) => MapEntry(k, v.toString())),
        createdAt: DateTime.parse(j['created_at'] as String),
      );

  Future<List<Map<String, dynamic>>> _catalog({String? category}) async {
    final services = <Map<String, dynamic>>[];
    var page = 1;
    var lastPage = 1;
    do {
      final response = await _dio.get('/api/v1/services', queryParameters: {
        'page': page,
        'per_page': 100,
        if (category != null) 'category': category,
      });
      services
          .addAll((response.data['data'] as List).cast<Map<String, dynamic>>());
      lastPage = response.data['meta']['last_page'] as int;
      page++;
    } while (page <= lastPage);
    return services;
  }
}
