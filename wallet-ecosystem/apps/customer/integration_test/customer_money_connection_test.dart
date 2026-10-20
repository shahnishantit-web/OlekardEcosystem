import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:integration_test/integration_test.dart';
import 'package:uuid/uuid.dart';
import 'package:wallet_customer/core/di/providers.dart';
import 'package:wallet_customer/core/router/app_router.dart';
import 'package:wallet_customer/core/security/biometric_service.dart';
import 'package:wallet_customer/modules/auth/presentation/auth_providers.dart';
import 'package:wallet_customer/modules/payment/application/customer_money_service.dart';
import 'package:wallet_customer/modules/profile/presentation/profile_providers.dart';
import 'package:wallet_customer/modules/wallet/presentation/wallet_providers.dart';

class _PinOnly extends BiometricService {
  @override
  Future<bool> isAvailable() async => false;
}

// Explicitly opt in: this creates small real ledger entries in the local mock system.
void main() {
  IntegrationTestWidgetsFlutterBinding.ensureInitialized();
  testWidgets('Android customer profile, wallet and mock payment round trips',
      (tester) async {
    final container = ProviderContainer(overrides: [
      biometricServiceProvider.overrideWithValue(_PinOnly()),
    ]);
    addTearDown(container.dispose);
    await tester.runAsync(() async {
      await container.read(authProvider.notifier).login('+26771000001');
      await container.read(authProvider.notifier).verifyLogin('123456');
    });
    expect(container.read(authProvider).isAuthenticated, isTrue);
    await tester.pumpWidget(UncontrolledProviderScope(
        container: container,
        child:
            MaterialApp.router(routerConfig: container.read(routerProvider))));
    await tester.runAsync(() async {
      final profile = await container.read(profileProvider.future);
      expect(profile.phoneNumber, '+26771000001');
      final wallet =
          await container.read(walletRepositoryProvider).getMyWallet();
      final service = container.read(customerMoneyServiceProvider);
      final funding = await service.prepare(
          kind: MoneyFlowKind.funding,
          wallet: wallet,
          amount: 1000,
          target: '',
          method: 'bank_transfer',
          idempotencyKey: const Uuid().v4());
      final confirmed =
          await service.confirm(funding, '123456', const Uuid().v4());
      expect(confirmed['status'], 'awaiting_match');
      final key = const Uuid().v4();
      expect((await service.completeMockBank(funding.id, key))['status'],
          'completed');
      expect((await service.completeMockBank(funding.id, key))['status'],
          'completed');
      final funded =
          await container.read(walletRepositoryProvider).getMyWallet();
      expect(funded.balanceMinorUnits, wallet.balanceMinorUnits + 1000);
      final transfer = await service.prepare(
          kind: MoneyFlowKind.transfer,
          wallet: funded,
          amount: 100,
          target: '+26771000003',
          method: '',
          idempotencyKey: const Uuid().v4());
      expect(
          (await service.confirm(
              transfer, '123456', const Uuid().v4()))['status'],
          'completed');
      final catalog =
          await container.read(apiClientProvider).dio.get('/api/v1/services');
      final bill = (catalog.data['data'] as List)
          .firstWhere((item) => item['name'] == 'Development Mock Electricity');
      final payment = await service.prepare(
          kind: MoneyFlowKind.service,
          wallet: funded,
          amount: 100,
          target: 'DEV-ANDROID',
          method: '',
          serviceId: bill['id'] as String,
          idempotencyKey: const Uuid().v4());
      expect(
          (await service.confirm(
              payment, '123456', const Uuid().v4()))['status'],
          'completed');
      final withdrawal = await service.prepare(
          kind: MoneyFlowKind.withdrawal,
          wallet: funded,
          amount: 100,
          target: 'DEV0001',
          method: 'bank_transfer',
          bankCode: 'MOCK',
          idempotencyKey: const Uuid().v4());
      expect(
          (await service.confirm(
              withdrawal, '123456', const Uuid().v4()))['status'],
          'processing');
      expect(
          (await service.completeMockBank(withdrawal.id, const Uuid().v4(),
              withdrawal: true))['status'],
          'completed');
      final finalWallet =
          await container.read(walletRepositoryProvider).getMyWallet();
      expect(finalWallet.balanceMinorUnits, wallet.balanceMinorUnits + 700);
    });
    for (final route in [
      '/profile',
      '/wallet',
      '/services',
      '/services/history',
      '/send',
      '/add-money',
      '/withdraw'
    ]) {
      container.read(routerProvider).go(route);
      await tester
          .runAsync(() => Future<void>.delayed(const Duration(seconds: 1)));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 500));
      expect(tester.takeException(), isNull, reason: route);
    }
    await tester.pumpWidget(const SizedBox.shrink());
  }, skip: !const bool.fromEnvironment('RUN_LOCAL_MONEY_SMOKE'));
}
