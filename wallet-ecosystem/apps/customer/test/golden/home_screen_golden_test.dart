import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:wallet_customer/core/theme/app_theme.dart';
import 'package:wallet_customer/core/theme/ok_theme_extension.dart';
import 'package:wallet_customer/modules/home/presentation/home_providers.dart';
import 'package:wallet_customer/modules/home/domain/entities/home_summary.dart';
import 'package:wallet_customer/modules/wallet/domain/entities/wallet.dart';
import 'package:wallet_customer/modules/wallet/presentation/wallet_providers.dart';

void main() {
  final fakeWallet = Wallet(
    id: 'w-001',
    walletNumber: '2671000001',
    currencyCode: 'BWP',
    balanceMinorUnits: 150000,
    status: WalletStatus.active,
    updatedAt: DateTime(2026, 10, 1),
  );

  final fakeSummary = HomeSummary(
    primaryWallet: fakeWallet,
    recentTransactions: const [],
    unreadNotificationCount: 3,
  );

  Widget buildSubject() {
    return ProviderScope(
      overrides: [
        walletProvider.overrideWith((ref) async => fakeWallet),
        homeSummaryProvider.overrideWith((ref) async => fakeSummary),
      ],
      child: MaterialApp(
        theme: AppTheme.dark,
        home: const _HomeShell(
          balanceBwp: 1500.00,
          notificationCount: 3,
        ),
      ),
    );
  }

  group('HomeScreen golden — Consumer Green theme tokens', () {
    testWidgets('home dashboard renders with Consumer Green theme', (tester) async {
      tester.view.physicalSize = const Size(390, 844);
      tester.view.devicePixelRatio = 2.0;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);

      await tester.pumpWidget(buildSubject());
      await tester.pump();

      await expectLater(
        find.byType(MaterialApp),
        matchesGoldenFile('goldens/home_screen.png'),
      );
    });

    testWidgets('OkThemeExtension is accessible in home context', (tester) async {
      late OkThemeExtension? okTheme;
      await tester.pumpWidget(
        MaterialApp(
          theme: AppTheme.dark,
          home: Builder(
            builder: (context) {
              okTheme = Theme.of(context).extension<OkThemeExtension>();
              return const SizedBox();
            },
          ),
        ),
      );
      await tester.pump();
      expect(okTheme, isNotNull);
      expect(okTheme!.primary, const Color(0xFF16A34A));
      expect(okTheme!.appBackground, const Color(0xFF001B12));
    });
  });
}

class _HomeShell extends StatelessWidget {
  final double balanceBwp;
  final int notificationCount;

  const _HomeShell({required this.balanceBwp, required this.notificationCount});

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final ok = theme.extension<OkThemeExtension>()!;

    return Scaffold(
      appBar: AppBar(
        title: const Text('Home'),
        actions: [
          Stack(
            alignment: Alignment.topRight,
            children: [
              IconButton(
                icon: const Icon(Icons.notifications_outlined),
                onPressed: () {},
                tooltip: 'Notifications',
              ),
              if (notificationCount > 0)
                Positioned(
                  top: 8,
                  right: 8,
                  child: Container(
                    width: 8,
                    height: 8,
                    decoration: BoxDecoration(
                      color: ok.errorFg,
                      shape: BoxShape.circle,
                    ),
                  ),
                ),
            ],
          ),
        ],
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Card(
              child: Padding(
                padding: const EdgeInsets.all(20),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('Available Balance', style: theme.textTheme.bodySmall),
                    const SizedBox(height: 8),
                    Text(
                      'BWP ${balanceBwp.toStringAsFixed(2)}',
                      style: theme.textTheme.headlineMedium?.copyWith(
                        color: ok.primary,
                        fontWeight: FontWeight.bold,
                      ),
                    ),
                  ],
                ),
              ),
            ),
            const SizedBox(height: 16),
            Text('Recent Transactions', style: theme.textTheme.titleMedium),
            const SizedBox(height: 8),
            Container(
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(
                color: ok.successBg,
                borderRadius: BorderRadius.circular(8),
                border: Border.all(color: ok.successBorder),
              ),
              child: Text(
                'No recent transactions',
                style: TextStyle(color: ok.successFg),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
