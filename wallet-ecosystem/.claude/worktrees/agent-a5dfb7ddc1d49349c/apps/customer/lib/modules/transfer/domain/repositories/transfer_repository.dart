import '../entities/transfer_result.dart';

abstract interface class TransferRepository {
  Future<WalletLookupResult> lookupWallet(String walletNumber);
  Future<TransferPreview> previewTransfer({
    required String recipientWalletNumber,
    required int amountMinorUnits,
    required String currencyCode,
  });
  Future<TransferResult> executeTransfer(TransferRequestDto request);
}
