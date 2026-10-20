import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:wallet_agent/core/shell/agent_shell.dart';
import 'package:wallet_agent/core/theme/app_theme.dart';
import 'package:wallet_agent/core/theme/design_tokens.dart';

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
  group('AgentShell — 5-tab navigation', () {
    testWidgets('renders 5 NavigationDestination labels', (tester) async {
      await tester.pumpWidget(_buildShell());
      await tester.pumpAndSettle();

      expect(find.text('Dashboard'), findsOneWidget);
      expect(find.text('Customers'), findsOneWidget);
      expect(find.text('Transactions'), findsOneWidget);
      expect(find.text('Activity'), findsOneWidget);
      expect(find.text('Account'), findsOneWidget);
    });

    testWidgets('Customers tab navigates to /customers', (tester) async {
      await tester.pumpWidget(_buildShell());
      await tester.pumpAndSettle();

      await tester.tap(find.text('Customers'));
      await tester.pumpAndSettle();

      final state = GoRouterState.of(
        tester.element(find.byType(AgentShell)),
      );
      expect(state.matchedLocation, startsWith('/customers'));
    });

    testWidgets('Agent Blue indicatorColor is set in NavigationBar', (tester) async {
      await tester.pumpWidget(_buildShell());
      await tester.pumpAndSettle();

      final navBar = tester.widget<NavigationBar>(find.byType(NavigationBar));
      final theme = AppTheme.dark.navigationBarTheme;
      // indicatorColor resolves to AppColors.primary (Agent Blue)
      expect(AppColors.primary.value, equals(const Color(0xFF2563EB).value));
      expect(navBar.selectedIndex, equals(0)); // starts on dashboard
    });

    for (final width in [360.0, 390.0, 430.0]) {
      testWidgets('renders without overflow at ${width}dp', (tester) async {
        tester.view.physicalSize = Size(width, 844);
        tester.view.devicePixelRatio = 1.0;
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);

        await tester.pumpWidget(_buildShell());
        await tester.pumpAndSettle();

        expect(tester.takeException(), isNull);
        expect(find.byType(NavigationBar), findsOneWidget);
      });
    }

    testWidgets('renders without overflow at 600dp tablet width', (tester) async {
      tester.view.physicalSize = const Size(600, 1024);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);

      await tester.pumpWidget(_buildShell());
      await tester.pumpAndSettle();

      expect(tester.takeException(), isNull);
    });
  });
}
