import 'package:wallet_agent/modules/cash_in/domain/entities/cash_in_result.dart';
import 'package:wallet_agent/modules/cash_in/domain/entities/customer_lookup.dart';
import 'package:wallet_agent/modules/cash_in/data/models/cash_in_request_dto.dart';

abstract class CashInRepository {
  Future<CustomerLookup> searchCustomer(String query);
  Future<CashInResult> submitCashIn(CashInRequestDto request);
}
