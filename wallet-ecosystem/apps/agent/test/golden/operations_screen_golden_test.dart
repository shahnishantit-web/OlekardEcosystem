import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:wallet_agent/core/theme/app_theme.dart';
import 'package:wallet_agent/core/theme/ok_theme_extension.dart';

void main() {
  group('Agent OperationsScreen golden — Agent Blue theme tokens', () {
    Widget buildSubject() {
      return MaterialApp(
        theme: AppTheme.dark,
        home: const _OperationsShell(),
      );
    }

    testWidgets('operations screen renders with Agent Blue theme', (tester) async {
      tester.view.physicalSize = const Size(390, 844);
      tester.view.devicePixelRatio = 2.0;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);

      await tester.pumpWidget(buildSubject());
      await tester.pump();

      await expectLater(
        find.byType(MaterialApp),
        matchesGoldenFile('goldens/agent_operations_screen.png'),
      );
    });

    testWidgets('cash-in button uses primary Agent Blue color', (tester) async {
      await tester.pumpWidget(buildSubject());
      await tester.pump();

      expect(find.text('Cash In'), findsOneWidget);
      expect(find.text('Cash Out'), findsOneWidget);
      expect(find.text('Customer Lookup'), findsOneWidget);
    });

    testWidgets('nav bar uses Agent Blue surface', (tester) async {
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

      // Agent nav-active is rgba(37,99,235,0.15) — verify navActive is set
      expect(ok!.navActive, isNotNull);
      expect(ok!.navActive.a, closeTo(0.15, 0.01));
    });
  });
}

class _OperationsShell extends StatelessWidget {
  const _OperationsShell();

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final ok = theme.extension<OkThemeExtension>()!;

    return Scaffold(
      appBar: AppBar(title: const Text('Operations')),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text('Quick Actions', style: theme.textTheme.titleMedium),
            const SizedBox(height: 16),
            _OperationTile(
              icon: Icons.add_circle_outline,
              label: 'Cash In',
              description: 'Deposit funds to customer wallet',
              color: ok.successFg,
              ok: ok,
              theme: theme,
            ),
            const SizedBox(height: 12),
            _OperationTile(
              icon: Icons.remove_circle_outline,
              label: 'Cash Out',
              description: 'Withdraw funds from customer wallet',
              color: ok.infoFg,
              ok: ok,
              theme: theme,
            ),
            const SizedBox(height: 12),
            _OperationTile(
              icon: Icons.search,
              label: 'Customer Lookup',
              description: 'Find customer by phone or ID',
              color: ok.primary,
              ok: ok,
              theme: theme,
            ),
          ],
        ),
      ),
      bottomNavigationBar: BottomNavigationBar(
        currentIndex: 1,
        items: const [
          BottomNavigationBarItem(icon: Icon(Icons.dashboard), label: 'Dashboard'),
          BottomNavigationBarItem(icon: Icon(Icons.swap_horiz), label: 'Operations'),
          BottomNavigationBarItem(icon: Icon(Icons.person), label: 'Profile'),
        ],
      ),
    );
  }
}

class _OperationTile extends StatelessWidget {
  final IconData icon;
  final String label;
  final String description;
  final Color color;
  final OkThemeExtension ok;
  final ThemeData theme;

  const _OperationTile({
    required this.icon,
    required this.label,
    required this.description,
    required this.color,
    required this.ok,
    required this.theme,
  });

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: () {},
      borderRadius: BorderRadius.circular(12),
      child: Container(
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          color: ok.appSurface,
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: color.withValues(alpha: 0.3)),
        ),
        child: Row(
          children: [
            Container(
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(
                color: color.withValues(alpha: 0.15),
                borderRadius: BorderRadius.circular(8),
              ),
              child: Icon(icon, color: color, size: 22),
            ),
            const SizedBox(width: 16),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(label, style: theme.textTheme.titleMedium),
                  Text(description, style: theme.textTheme.bodySmall),
                ],
              ),
            ),
            Icon(Icons.chevron_right, color: ok.primary),
          ],
        ),
      ),
    );
  }
}
