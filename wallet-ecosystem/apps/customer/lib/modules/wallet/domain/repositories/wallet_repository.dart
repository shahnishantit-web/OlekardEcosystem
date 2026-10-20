import '../entities/wallet.dart';

abstract interface class WalletRepository {
  Future<Wallet> getMyWallet();
  Future<WalletLimits> getWalletLimits();
  Future<String> getReceiveQrData();
}
