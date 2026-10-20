import '../../../wallet/domain/entities/wallet.dart';
import '../../../transactions/domain/entities/transaction.dart';

class HomeSummary {
  final Wallet primaryWallet;
  final List<Transaction> recentTransactions;
  final int unreadNotificationCount;

  const HomeSummary({
    required this.primaryWallet,
    required this.recentTransactions,
    required this.unreadNotificationCount,
  });
}
