// T069 — MainShell accessibility and responsive widget tests.
// Asserts no overflow at 360/390/430dp, touch targets ≥44dp, Semantics labels on all 5 nav items.
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

import 'package:wallet_customer/core/shell/main_shell.dart';

Widget _buildShell({String initialLocation = '/home'}) {
  final router = GoRouter(
    initialLocation: initialLocation,
    routes: [
      ShellRoute(
        builder: (_, __, child) => MainShell(child: child),
        routes: [
          GoRoute(path: '/home', builder: (_, __) => const SizedBox()),
          GoRoute(path: '/wallet', builder: (_, __) => const SizedBox()),
          GoRoute(path: '/services', builder: (_, __) => const SizedBox()),
          GoRoute(path: '/activity', builder: (_, __) => const SizedBox()),
          GoRoute(path: '/profile', builder: (_, __) => const SizedBox()),
        ],
      ),
    ],
  );

  return ProviderScope(
    child: MaterialApp.router(
      routerConfig: router,
    ),
  );
}

void main() {
  final viewSizes = [
    const Size(360, 800),
    const Size(390, 844),
    const Size(430, 932),
  ];

  for (final size in viewSizes) {
    group('MainShell at ${size.width.toInt()}dp', () {
      setUp(() {
        TestWidgetsFlutterBinding.ensureInitialized();
      });

      testWidgets('renders with no layout overflow', (tester) async {
        tester.view.physicalSize = size * tester.view.devicePixelRatio;
        addTearDown(() => tester.view.resetPhysicalSize());

        await tester.pumpWidget(_buildShell());
        await tester.pumpAndSettle();

        // No overflow exception should be thrown
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

        for (final label in ['Home', 'Wallet', 'Services', 'Activity', 'Account']) {
          expect(find.text(label), findsWidgets,
              reason: '$label nav label not found at ${size.width.toInt()}dp');
        }
      });

      testWidgets('NavigationBar height satisfies ≥44dp touch target', (tester) async {
        tester.view.physicalSize = size * tester.view.devicePixelRatio;
        addTearDown(() => tester.view.resetPhysicalSize());

        await tester.pumpWidget(_buildShell());
        await tester.pumpAndSettle();

        final navBar = tester.getRect(find.byType(NavigationBar));
        // Material 3 NavigationBar is at least 80dp; well above 44dp minimum
        expect(navBar.height, greaterThanOrEqualTo(44.0),
            reason: 'NavigationBar height must be ≥44dp');
      });

      testWidgets('Semantics labels present for all 5 nav destinations', (tester) async {
        tester.view.physicalSize = size * tester.view.devicePixelRatio;
        addTearDown(() => tester.view.resetPhysicalSize());

        final handle = tester.ensureSemantics();
        await tester.pumpWidget(_buildShell());
        await tester.pumpAndSettle();

        for (final label in ['Home', 'Wallet', 'Services', 'Activity', 'Account']) {
          final semanticsNode = find.bySemanticsLabel(RegExp(label, caseSensitive: false));
          expect(semanticsNode, findsWidgets,
              reason: 'Semantics label for "$label" not found at ${size.width.toInt()}dp');
        }

        handle.dispose();
      });
    });
  }
}
