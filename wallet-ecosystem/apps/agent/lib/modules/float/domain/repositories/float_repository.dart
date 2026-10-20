import 'package:wallet_agent/core/api/api_response.dart';
import 'package:wallet_agent/modules/float/domain/entities/float_balance.dart';

abstract class FloatRepository {
  Future<FloatBalance> getBalance();
  Future<PaginatedResult<FloatTransaction>> getTransactions({
    int page = 1,
    DateTime? fromDate,
    DateTime? toDate,
  });
  Future<FloatTopUpResult> requestTopUp(FloatTopUpRequest request);
}
