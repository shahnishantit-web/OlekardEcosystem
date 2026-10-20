import 'package:wallet_agent/core/api/api_response.dart';
import 'package:wallet_agent/modules/commissions/domain/entities/commission_balance.dart';

abstract class CommissionRepository {
  Future<CommissionBalance> getBalance();
  Future<PaginatedResult<CommissionEntry>> getEntries({
    int page = 1,
    String? type,
    DateTime? fromDate,
    DateTime? toDate,
  });
}
