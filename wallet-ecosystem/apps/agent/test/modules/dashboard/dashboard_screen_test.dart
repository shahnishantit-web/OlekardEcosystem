import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:wallet_agent/core/theme/app_theme.dart';
import 'package:wallet_agent/modules/dashboard/presentation/dashboard_providers.dart';
import 'package:wallet_agent/modules/dashboard/presentation/screens/dashboard_screen.dart';
import 'package:wallet_agent/modules/notifications/presentation/notification_providers.dart';

void main() {
  testWidgets('keeps scoped dashboard shortcuts available when metrics fail',
      (tester) async {
    final semantics = tester.ensureSemantics();
    try {
      await tester.pumpWidget(
        ProviderScope(
          overrides: [
            dashboardProvider.overrideWith(
              (ref) async => throw StateError('test dashboard unavailable'),
            ),
            unreadCountProvider.overrideWith((ref) async => 0),
          ],
          child: MaterialApp(
            theme: AppTheme.dark,
            home: const DashboardScreen(),
          ),
        ),
      );
      await tester.pumpAndSettle();

      expect(find.text('Failed to load dashboard'), findsOneWidget);
      expect(find.byTooltip('Open Event Gate scanner'), findsOneWidget);
      expect(find.text('Event Gate'), findsOneWidget);
      expect(find.bySemanticsLabel(RegExp('Event Gate')), findsWidgets);
      expect(find.bySemanticsLabel(RegExp('Cash In')), findsOneWidget);
      expect(find.bySemanticsLabel(RegExp('Cash Out')), findsOneWidget);
    } finally {
      semantics.dispose();
    }
  });
}
