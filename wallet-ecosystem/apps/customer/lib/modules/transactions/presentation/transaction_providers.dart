import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../domain/entities/transaction.dart';
import '../domain/repositories/transaction_repository.dart';
import '../data/repositories/transaction_repository_impl.dart';
import '../../../../core/api/paginated_result.dart';
import '../../../core/di/providers.dart';

final transactionRepositoryProvider = Provider<TransactionRepository>((ref) {
  return TransactionRepositoryImpl(apiClient: ref.watch(apiClientProvider));
});

final transactionListProvider = FutureProvider.autoDispose
    .family<PaginatedResult<Transaction>, int>((ref, page) async {
  return ref.watch(transactionRepositoryProvider).listTransactions(page: page);
});

final transactionDetailProvider =
    FutureProvider.autoDispose.family<Transaction, String>((ref, id) async {
  return ref.watch(transactionRepositoryProvider).getTransaction(id);
});
