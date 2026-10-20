import '../entities/withdrawal_result.dart';

abstract interface class WithdrawalRepository {
  Future<WithdrawalPreview> previewWithdrawal({
    required String agentWalletNumber,
    required int amountMinorUnits,
    required String currencyCode,
  });
  Future<WithdrawalResult> executeWithdrawal(WithdrawRequestDto request);
}
