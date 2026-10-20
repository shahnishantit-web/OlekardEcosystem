import 'package:dio/dio.dart';
import '../../../../core/api/api_client.dart';
import '../../../../core/api/paginated_result.dart';
import '../../domain/entities/transaction.dart';
import '../../domain/repositories/transaction_repository.dart';
import '../models/transaction_dto.dart';

class TransactionRepositoryImpl implements TransactionRepository {
  final Dio _dio;

  TransactionRepositoryImpl({required ApiClient apiClient}) : _dio = apiClient.dio;

  @override
  Future<PaginatedResult<Transaction>> listTransactions({
    int page = 1,
    int perPage = 20,
    String? type,
    String? status,
  }) async {
    final res = await _dio.get(
      '/v1/customer/transactions',
      queryParameters: {
        'page': page,
        'per_page': perPage,
        if (type != null) 'type': type,
        if (status != null) 'status': status,
      },
    );
    return PaginatedResult.fromJson(
      res.data as Map<String, dynamic>,
      TransactionDto.fromJson,
    );
  }

  @override
  Future<Transaction> getTransaction(String id) async {
    final res = await _dio.get('/v1/customer/transactions/$id');
    return TransactionDto.fromJson(res.data as Map<String, dynamic>);
  }
}
