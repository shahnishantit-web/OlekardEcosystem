import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:mockito/annotations.dart';

import 'package:wallet_customer/core/shell/main_shell.dart';

@GenerateNiceMocks([MockSpec<GoRouter>()])
void main() {
  Widget buildShell({String initialLocation = '/home'}) {
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

  group('MainShell — 5-tab navigation', () {
    testWidgets('renders 5 NavigationDestination labels', (tester) async {
      await tester.pumpWidget(buildShell());
      await tester.pumpAndSettle();

      expect(find.text('Home'), findsOneWidget);
      expect(find.text('Wallet'), findsOneWidget);
      expect(find.text('Services'), findsOneWidget);
      expect(find.text('Activity'), findsOneWidget);
      expect(find.text('Account'), findsOneWidget);
    });

    testWidgets('Activity tab (index 3) navigates to /activity',
        (tester) async {
      await tester.pumpWidget(buildShell());
      await tester.pumpAndSettle();

      await tester.tap(find.text('Activity'));
      await tester.pumpAndSettle();

      final state = GoRouterState.of(
        tester.element(find.byType(MainShell)),
      );
      expect(state.matchedLocation, startsWith('/activity'));
    });

    testWidgets('Account tab (index 4) navigates to /profile', (tester) async {
      await tester.pumpWidget(buildShell());
      await tester.pumpAndSettle();

      await tester.tap(find.text('Account'));
      await tester.pumpAndSettle();

      final state = GoRouterState.of(
        tester.element(find.byType(MainShell)),
      );
      expect(state.matchedLocation, startsWith('/profile'));
    });

    for (final width in [360.0, 390.0, 430.0]) {
      testWidgets('renders without overflow at ${width}dp', (tester) async {
        tester.view.physicalSize = Size(width, 844);
        tester.view.devicePixelRatio = 1.0;
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);

        await tester.pumpWidget(buildShell());
        await tester.pumpAndSettle();

        expect(tester.takeException(), isNull);
        expect(find.byType(NavigationBar), findsOneWidget);
      });
    }
  });
}
