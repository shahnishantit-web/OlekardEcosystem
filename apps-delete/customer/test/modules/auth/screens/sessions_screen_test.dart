import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:customer/modules/auth/models/auth_models.dart';
import 'package:customer/modules/auth/screens/sessions/sessions_screen.dart';
import 'package:customer/modules/auth/services/auth_api_service.dart';
import 'package:customer/modules/auth/state/auth_state.dart';
import 'package:customer/modules/auth/state/auth_state_notifier.dart';
import 'package:customer/modules/auth/storage/auth_token_storage.dart';

class _AuthenticatedNotifier extends AuthStateNotifier {
  @override
  Future<AuthState> build() async =>
      const AuthAuthenticated(sessionId: 'session-001');
}

class _MockApiService extends AuthApiService {
  _MockApiService() : super.forTest();

  List<SessionInfo> sessions = [
    SessionInfo(
      sessionId: 'session-001',
      lastActivityAt: DateTime.now().subtract(const Duration(minutes: 5)),
      expiresAt: DateTime.now().add(const Duration(minutes: 10)),
      isCurrent: true,
      deviceName: 'iPhone 15 Pro',
      platform: 'ios',
    ),
    SessionInfo(
      sessionId: 'session-002',
      lastActivityAt: DateTime.now().subtract(const Duration(hours: 2)),
      expiresAt: DateTime.now().add(const Duration(hours: 8)),
      isCurrent: false,
      deviceName: 'Samsung Galaxy S24',
      platform: 'android',
    ),
  ];

  String? revokedSessionId;

  @override
  Future<List<SessionInfo>> listSessions() async => sessions;

  @override
  Future<void> revokeSession(String sessionId) async {
    revokedSessionId = sessionId;
    sessions = sessions.where((s) => s.sessionId != sessionId).toList();
  }
}

Widget _wrap(Widget child,
    {AuthStateNotifier? notifier, AuthApiService? api}) {
  return ProviderScope(
    overrides: [
      authStateNotifierProvider.overrideWith(
          () => notifier ?? _AuthenticatedNotifier()),
      authTokenStorageProvider.overrideWith((_) => throw UnimplementedError()),
      authApiServiceProvider
          .overrideWithValue(api ?? _MockApiService()),
    ],
    child: MaterialApp(home: child),
  );
}

void main() {
  group('SessionsScreen — widget tests (T171)', () {
    testWidgets('lists sessions returned by the API', (tester) async {
      await tester.pumpWidget(_wrap(const SessionsScreen()));
      await tester.pumpAndSettle();

      expect(find.text('iPhone 15 Pro'), findsOneWidget);
      expect(find.text('Samsung Galaxy S24'), findsOneWidget);
    });

    testWidgets('"This device" badge appears on current session', (tester) async {
      await tester.pumpWidget(_wrap(const SessionsScreen()));
      await tester.pumpAndSettle();

      expect(find.text('This device'), findsOneWidget);
    });

    testWidgets('revoke icon absent on current session', (tester) async {
      await tester.pumpWidget(_wrap(const SessionsScreen()));
      await tester.pumpAndSettle();

      // Only 1 revoke icon — for session-002 (not current).
      expect(find.byIcon(Icons.logout), findsOneWidget);
    });

    testWidgets('revoking a session removes it from the list', (tester) async {
      final api = _MockApiService();
      await tester.pumpWidget(_wrap(const SessionsScreen(), api: api));
      await tester.pumpAndSettle();

      await tester.tap(find.byIcon(Icons.logout));
      await tester.pumpAndSettle();

      expect(api.revokedSessionId, 'session-002');
      expect(find.text('Samsung Galaxy S24'), findsNothing);
    });

    // ── Accessibility (T173) ─────────────────────────────────────────────────

    testWidgets('session tile has meaningful semantics label', (tester) async {
      await tester.pumpWidget(_wrap(const SessionsScreen()));
      await tester.pumpAndSettle();

      expect(
        find.bySemanticsLabel(
            RegExp(r'Current session.*iPhone 15 Pro')),
        findsOneWidget,
      );
    });

    testWidgets('revoke button has semantics label identifying the device',
        (tester) async {
      await tester.pumpWidget(_wrap(const SessionsScreen()));
      await tester.pumpAndSettle();

      expect(
        find.bySemanticsLabel(
            RegExp(r'Revoke session on Samsung Galaxy S24')),
        findsOneWidget,
      );
    });
  });
}
