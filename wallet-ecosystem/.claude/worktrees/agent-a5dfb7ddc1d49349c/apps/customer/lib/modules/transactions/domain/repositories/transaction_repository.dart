import '../../../../core/api/paginated_result.dart';
import '../entities/transaction.dart';

abstract interface class TransactionRepository {
  Future<PaginatedResult<Transaction>> listTransactions({
    int page = 1,
    int perPage = 20,
    String? type,
    String? status,
  });

  Future<Transaction> getTransaction(String id);
}
