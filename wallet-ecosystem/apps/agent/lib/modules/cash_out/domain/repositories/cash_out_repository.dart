import 'package:wallet_agent/modules/cash_out/domain/entities/cash_out_result.dart';
import 'package:wallet_agent/modules/cash_out/domain/entities/pickup_code_lookup.dart';
import 'package:wallet_agent/modules/cash_out/data/models/cash_out_request_dto.dart';
import 'package:wallet_agent/modules/cash_in/domain/entities/customer_lookup.dart';

abstract class CashOutRepository {
  Future<CustomerLookup> searchCustomer(String query);
  Future<PickupCodeLookup> lookupPickupCode(String code);
  Future<CashOutResult> submitCashOut(CashOutRequestDto request);
}
