import 'package:flutter_test/flutter_test.dart';
import 'package:wallet_customer/modules/wallet/domain/entities/wallet.dart';

void main() {
  group('Wallet entity', () {
    final wallet = Wallet(
      id: 'w-001',
      walletNumber: 'WAL-0001',
      currencyCode: 'KES',
      balanceMinorUnits: 50000,
      status: WalletStatus.active,
      updatedAt: DateTime(2024, 1, 1),
    );

    test('balanceMajor computes correctly from minor units', () {
      expect(wallet.balanceMajor, closeTo(500.0, 0.001));
    });

    test('status is active', () {
      expect(wallet.status, WalletStatus.active);
    });
  });

  group('WalletLimits entity', () {
    const limits = WalletLimits(
      minFundMinorUnits: 100,
      maxFundMinorUnits: 10000000,
      maxDailyTransferMinorUnits: 5000000,
      minWithdrawalMinorUnits: 500,
      dailyUsedMinorUnits: 1000,
    );

    test('fields are accessible', () {
      expect(limits.minFundMinorUnits, 100);
      expect(limits.maxFundMinorUnits, 10000000);
      expect(limits.dailyUsedMinorUnits, 1000);
    });
  });
}
