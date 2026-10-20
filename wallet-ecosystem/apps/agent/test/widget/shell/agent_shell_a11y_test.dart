// T070 — AgentShell accessibility and responsive widget tests.
// Asserts no overflow at 360/390/430dp and tablet 600dp, touch targets ≥44dp.
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

import 'package:wallet_agent/core/shell/agent_shell.dart';
import 'package:wallet_agent/core/theme/app_theme.dart';

Widget _buildShell({String initialLocation = '/dashboard'}) {
  final router = GoRouter(
    initialLocation: initialLocation,
    routes: [
      ShellRoute(
        builder: (_, __, child) => AgentShell(child: child),
        routes: [
          GoRoute(path: '/dashboard', builder: (_, __) => const SizedBox()),
          GoRoute(path: '/customers', builder: (_, __) => const SizedBox()),
          GoRoute(path: '/transactions', builder: (_, __) => const SizedBox()),
          GoRoute(path: '/notifications', builder: (_, __) => const SizedBox()),
          GoRoute(path: '/profile', builder: (_, __) => const SizedBox()),
        ],
      ),
    ],
  );

  return MaterialApp.router(
    theme: AppTheme.dark,
    routerConfig: router,
  );
}

void main() {
  final viewSizes = [
    const Size(360, 800),
    const Size(390, 844),
    const Size(430, 932),
    const Size(600, 1024), // tablet
  ];

  for (final size in viewSizes) {
    final label = size.width >= 600 ? 'tablet ${size.width.toInt()}dp' : '${size.width.toInt()}dp';

    group('AgentShell at $label', () {
      testWidgets('renders with no layout overflow', (tester) async {
        tester.view.physicalSize = size * tester.view.devicePixelRatio;
        addTearDown(() => tester.view.resetPhysicalSize());

        await tester.pumpWidget(_buildShell());
        await tester.pumpAndSettle();

        expect(tester.takeException(), isNull);
      });

      testWidgets('bottom NavigationBar is present', (tester) async {
        tester.view.physicalSize = size * tester.view.devicePixelRatio;
        addTearDown(() => tester.view.resetPhysicalSize());

        await tester.pumpWidget(_buildShell());
        await tester.pumpAndSettle();

        expect(find.byType(NavigationBar), findsOneWidget);
      });

      testWidgets('all 5 navigation labels are rendered', (tester) async {
        tester.view.physicalSize = size * tester.view.devicePixelRatio;
        addTearDown(() => tester.view.resetPhysicalSize());

        await tester.pumpWidget(_buildShell());
        await tester.pumpAndSettle();

        for (final navLabel in ['Dashboard', 'Customers', 'Transactions', 'Activity', 'Account']) {
          expect(find.text(navLabel), findsWidgets,
              reason: '$navLabel nav label not found at $label');
        }
      });

      testWidgets('NavigationBar height satisfies ≥44dp touch target', (tester) async {
        tester.view.physicalSize = size * tester.view.devicePixelRatio;
        addTearDown(() => tester.view.resetPhysicalSize());

        await tester.pumpWidget(_buildShell());
        await tester.pumpAndSettle();

        final navBarRect = tester.getRect(find.byType(NavigationBar));
        expect(navBarRect.height, greaterThanOrEqualTo(44.0),
            reason: 'NavigationBar height must be ≥44dp at $label');
      });

      testWidgets('Semantics labels present for all 5 nav destinations', (tester) async {
        tester.view.physicalSize = size * tester.view.devicePixelRatio;
        addTearDown(() => tester.view.resetPhysicalSize());

        final handle = tester.ensureSemantics();
        await tester.pumpWidget(_buildShell());
        await tester.pumpAndSettle();

        for (final navLabel in ['Dashboard', 'Customers', 'Transactions', 'Activity', 'Account']) {
          final semanticsNode = find.bySemanticsLabel(RegExp(navLabel, caseSensitive: false));
          expect(semanticsNode, findsWidgets,
              reason: 'Semantics label for "$navLabel" not found at $label');
        }

        handle.dispose();
      });
    });
  }
}
