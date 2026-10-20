import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'package:wallet_agent/core/api/api_client.dart';
import 'package:wallet_agent/core/api/api_response.dart';
import 'package:wallet_agent/modules/transactions/data/repositories/transaction_repository_impl.dart';
import 'package:wallet_agent/modules/transactions/domain/entities/agent_transaction.dart';
import 'package:wallet_agent/modules/transactions/domain/repositories/transaction_repository.dart';

final transactionRepositoryProvider = Provider<TransactionRepository>((ref) {
  return TransactionRepositoryImpl(dio: createDio(null));
});

final transactionsProvider =
    FutureProvider.autoDispose<PaginatedResult<AgentTransaction>>((ref) async {
  return ref.read(transactionRepositoryProvider).getTransactions();
});

final transactionDetailProvider = FutureProvider.autoDispose
    .family<AgentTransaction, String>((ref, id) async {
  return ref.read(transactionRepositoryProvider).getTransactionDetail(id);
});
