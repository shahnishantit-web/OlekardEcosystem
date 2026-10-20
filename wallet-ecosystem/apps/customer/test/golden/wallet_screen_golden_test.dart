import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:wallet_customer/core/theme/app_theme.dart';
import 'package:wallet_customer/core/theme/ok_theme_extension.dart';
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

  Widget buildSubject() {
    return ProviderScope(
      overrides: [
        walletProvider.overrideWith((ref) async => fakeWallet),
      ],
      child: MaterialApp(
        theme: AppTheme.dark,
        home: _WalletShell(wallet: fakeWallet),
      ),
    );
  }

  group('WalletScreen golden — Consumer Green theme tokens', () {
    testWidgets('wallet screen renders with Consumer Green theme', (tester) async {
      tester.view.physicalSize = const Size(390, 844);
      tester.view.devicePixelRatio = 2.0;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);

      await tester.pumpWidget(buildSubject());
      await tester.pump();

      await expectLater(
        find.byType(MaterialApp),
        matchesGoldenFile('goldens/wallet_screen.png'),
      );
    });

    testWidgets('wallet card uses Consumer surface and border tokens', (tester) async {
      late OkThemeExtension? ok;
      await tester.pumpWidget(
        MaterialApp(
          theme: AppTheme.dark,
          home: Builder(
            builder: (context) {
              ok = Theme.of(context).extension<OkThemeExtension>();
              return const SizedBox();
            },
          ),
        ),
      );
      await tester.pump();

      // Verify Consumer surface = #062A1A and background = #001B12
      expect(ok!.appSurface, const Color(0xFF062A1A));
      expect(ok!.appBackground, const Color(0xFF001B12));
    });

    testWidgets('wallet balance text uses primary color token', (tester) async {
      await tester.pumpWidget(buildSubject());
      await tester.pump();

      expect(find.text('BWP 1,500.00'), findsOneWidget);
    });
  });
}

class _WalletShell extends StatelessWidget {
  final Wallet wallet;
  const _WalletShell({required this.wallet});

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final ok = theme.extension<OkThemeExtension>()!;

    return Scaffold(
      appBar: AppBar(title: const Text('My Wallet')),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Container(
              padding: const EdgeInsets.all(24),
              decoration: BoxDecoration(
                color: ok.appSurface,
                borderRadius: BorderRadius.circular(16),
                border: Border.all(color: ok.primary.withValues(alpha: 0.3)),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Container(
                        width: 8,
                        height: 8,
                        decoration: BoxDecoration(
                          color: ok.successFg,
                          shape: BoxShape.circle,
                        ),
                      ),
                      const SizedBox(width: 6),
                      Text(
                        'Active',
                        style: TextStyle(color: ok.successFg, fontSize: 12),
                      ),
                    ],
                  ),
                  const SizedBox(height: 16),
                  Text(
                    wallet.currencyCode,
                    style: theme.textTheme.bodySmall,
                  ),
                  const SizedBox(height: 4),
                  Text(
                    '${wallet.currencyCode} ${_formatAmount(wallet.balanceMajor)}',
                    style: theme.textTheme.headlineMedium?.copyWith(
                      color: ok.primary,
                      fontWeight: FontWeight.bold,
                      letterSpacing: -0.5,
                    ),
                  ),
                  const SizedBox(height: 8),
                  Text(
                    wallet.walletNumber,
                    style: theme.textTheme.bodySmall,
                  ),
                ],
              ),
            ),
            const SizedBox(height: 24),
            Row(
              children: [
                Expanded(
                  child: FilledButton.icon(
                    onPressed: () {},
                    icon: const Icon(Icons.add),
                    label: const Text('Add Money'),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: () {},
                    icon: const Icon(Icons.send),
                    label: const Text('Transfer'),
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  String _formatAmount(double amount) {
    final parts = amount.toStringAsFixed(2).split('.');
    final whole = parts[0].replaceAllMapped(
      RegExp(r'(\d{1,3})(?=(\d{3})+(?!\d))'),
      (m) => '${m[1]},',
    );
    return '$whole.${parts[1]}';
  }
}
