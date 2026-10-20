import 'package:wallet_agent/core/api/api_response.dart';
import 'package:wallet_agent/modules/transactions/domain/entities/agent_transaction.dart';

abstract class TransactionRepository {
  Future<PaginatedResult<AgentTransaction>> getTransactions({
    int page = 1,
    String? type,
    DateTime? from,
    DateTime? to,
  });
  Future<AgentTransaction> getTransactionDetail(String id);
}
