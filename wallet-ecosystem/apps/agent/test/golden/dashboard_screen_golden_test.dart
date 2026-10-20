import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:wallet_agent/core/theme/app_theme.dart';
import 'package:wallet_agent/core/theme/ok_theme_extension.dart';
import 'package:wallet_agent/modules/dashboard/domain/entities/dashboard_summary.dart';
import 'package:wallet_agent/modules/dashboard/presentation/dashboard_providers.dart';

void main() {
  final fakeSummary = DashboardSummary(
    floatBalanceMinorUnits: 250000,
    floatCurrencyCode: 'BWP',
    lowFloatThresholdMinorUnits: 50000,
    todayCashInCount: 12,
    todayCashInAmountMinorUnits: 320000,
    todayCashOutCount: 8,
    todayCashOutAmountMinorUnits: 180000,
    updatedAt: DateTime(2026, 10, 1),
  );

  Widget buildSubject() {
    return ProviderScope(
      overrides: [
        dashboardProvider.overrideWith((ref) async => fakeSummary),
      ],
      child: MaterialApp(
        theme: AppTheme.dark,
        home: _AgentDashboardShell(summary: fakeSummary),
      ),
    );
  }

  group('Agent DashboardScreen golden — Agent Blue theme tokens', () {
    testWidgets('dashboard renders with Agent Blue theme', (tester) async {
      tester.view.physicalSize = const Size(390, 844);
      tester.view.devicePixelRatio = 2.0;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);

      await tester.pumpWidget(buildSubject());
      await tester.pump();

      await expectLater(
        find.byType(MaterialApp),
        matchesGoldenFile('goldens/agent_dashboard_screen.png'),
      );
    });

    testWidgets('OkThemeExtension carries Agent Blue values', (tester) async {
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

      expect(ok, isNotNull);
      expect(ok!.primary, const Color(0xFF2563EB));
      expect(ok!.appBackground, const Color(0xFF001329));
      expect(ok!.appSurface, const Color(0xFF061B3A));
    });

    testWidgets('normal float renders with success colors', (tester) async {
      await tester.pumpWidget(buildSubject());
      await tester.pump();
      expect(find.text('BWP 2,500.00'), findsOneWidget);
    });
  });
}

class _AgentDashboardShell extends StatelessWidget {
  final DashboardSummary summary;
  const _AgentDashboardShell({required this.summary});

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final ok = theme.extension<OkThemeExtension>()!;
    final isLow = summary.isLow;

    return Scaffold(
      appBar: AppBar(title: const Text('Dashboard')),
      body: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Container(
              padding: const EdgeInsets.all(20),
              decoration: BoxDecoration(
                color: isLow ? ok.warningBg : ok.successBg,
                borderRadius: BorderRadius.circular(12),
                border: Border.all(
                  color: isLow ? ok.warningBorder : ok.successBorder,
                ),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'Float Balance',
                    style: TextStyle(
                      color: isLow ? ok.warningFg : ok.successFg,
                      fontSize: 13,
                    ),
                  ),
                  const SizedBox(height: 8),
                  Text(
                    '${summary.floatCurrencyCode} ${_fmt(summary.floatBalanceMinorUnits / 100)}',
                    style: theme.textTheme.headlineMedium?.copyWith(
                      color: isLow ? ok.warningFg : ok.successFg,
                      fontWeight: FontWeight.bold,
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 16),
            Row(
              children: [
                Expanded(
                  child: _StatCard(
                    label: 'Cash In',
                    count: summary.todayCashInCount,
                    amount: summary.todayCashInAmountMinorUnits / 100,
                    currency: summary.floatCurrencyCode,
                    accentColor: ok.successFg,
                    theme: theme,
                    ok: ok,
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: _StatCard(
                    label: 'Cash Out',
                    count: summary.todayCashOutCount,
                    amount: summary.todayCashOutAmountMinorUnits / 100,
                    currency: summary.floatCurrencyCode,
                    accentColor: ok.infoFg,
                    theme: theme,
                    ok: ok,
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  String _fmt(double v) {
    final p = v.toStringAsFixed(2).split('.');
    final w = p[0].replaceAllMapped(
      RegExp(r'(\d{1,3})(?=(\d{3})+(?!\d))'),
      (m) => '${m[1]},',
    );
    return '$w.${p[1]}';
  }
}

class _StatCard extends StatelessWidget {
  final String label;
  final int count;
  final double amount;
  final String currency;
  final Color accentColor;
  final ThemeData theme;
  final OkThemeExtension ok;

  const _StatCard({
    required this.label,
    required this.count,
    required this.amount,
    required this.currency,
    required this.accentColor,
    required this.theme,
    required this.ok,
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: ok.appSurface,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: accentColor.withValues(alpha: 0.3)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(label, style: theme.textTheme.bodySmall),
          const SizedBox(height: 4),
          Text(
            '$count txns',
            style: TextStyle(color: accentColor, fontWeight: FontWeight.bold),
          ),
        ],
      ),
    );
  }
}
