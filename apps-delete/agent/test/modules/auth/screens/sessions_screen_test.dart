import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agent/modules/auth/models/auth_models.dart';
import 'package:agent/modules/auth/services/auth_api_service.dart';
import 'package:agent/modules/auth/state/auth_state.dart';
import 'package:agent/modules/auth/state/auth_state_notifier.dart';
import 'package:agent/modules/auth/screens/sessions/sessions_screen.dart';
import 'package:agent/modules/auth/storage/auth_token_storage.dart';

// ── Stub auth notifier ────────────────────────────────────────────────────────

class _AuthedNotifier extends AuthStateNotifier {
  @override
  Future<AuthState> build() async =>
      const AuthAuthenticated(sessionId: 'ses-1');

  bool logoutCalled = false;

  @override
  Future<void> logout() async {
    logoutCalled = true;
  }
}

// ── Stub API service ─────────────────────────────────────────────────────────

class _NeverResolvingApi extends AuthApiService {
  _NeverResolvingApi() : super.forTest();

  @override
  Future<List<SessionInfo>> listSessions() => Completer<List<SessionInfo>>().future;
}

class _StubApi extends AuthApiService {
  _StubApi() : super.forTest();

  List<SessionInfo> sessions = [];
  String? revokedSessionId;
  bool revokeAllCalled = false;
  AuthApiException? errorToThrow;

  @override
  Future<List<SessionInfo>> listSessions() async {
    if (errorToThrow != null) throw errorToThrow!;
    return sessions;
  }

  @override
  Future<void> revokeSession(String sessionId) async {
    revokedSessionId = sessionId;
  }

  @override
  Future<void> revokeAllSessions() async {
    revokeAllCalled = true;
  }
}

// ── Helper ───────────────────────────────────────────────────────────────────

Widget _wrap(Widget child,
    {required AuthStateNotifier notifier, required AuthApiService api}) {
  return ProviderScope(
    overrides: [
      authStateNotifierProvider.overrideWith(() => notifier),
      authTokenStorageProvider.overrideWith((_) => throw UnimplementedError()),
      authApiServiceProvider.overrideWith((_) => api),
    ],
    child: MaterialApp(
      theme: ThemeData(splashFactory: NoSplash.splashFactory),
      home: child,
    ),
  );
}

SessionInfo _session({
  String id = 'ses-1',
  bool isCurrent = false,
  String? deviceName,
  String? platform,
}) =>
    SessionInfo(
      sessionId: id,
      lastActivityAt: DateTime.now().subtract(const Duration(hours: 1)),
      expiresAt: DateTime.now().add(const Duration(hours: 24)),
      isCurrent: isCurrent,
      deviceName: deviceName,
      platform: platform,
    );

// ── Tests ─────────────────────────────────────────────────────────────────────

void main() {
  group('SessionsScreen — widget tests (T176, T178)', () {
    testWidgets('shows empty state when no sessions', (tester) async {
      final api = _StubApi()..sessions = [];
      final notifier = _AuthedNotifier();
      await tester.pumpWidget(
          _wrap(const SessionsScreen(), notifier: notifier, api: api));
      await tester.pumpAndSettle();

      expect(find.text('No active sessions found.'), findsOneWidget);
    });

    testWidgets('shows sessions list', (tester) async {
      final api = _StubApi()
        ..sessions = [
          _session(id: 'ses-1', deviceName: 'My Phone', isCurrent: true),
          _session(id: 'ses-2', deviceName: 'Tablet', platform: 'android'),
        ];
      final notifier = _AuthedNotifier();
      await tester.pumpWidget(
          _wrap(const SessionsScreen(), notifier: notifier, api: api));
      await tester.pumpAndSettle();

      expect(find.text('My Phone'), findsOneWidget);
      expect(find.text('Tablet'), findsOneWidget);
      expect(find.text('This device'), findsOneWidget);
    });

    testWidgets('shows error state on API failure', (tester) async {
      final api = _StubApi()
        ..errorToThrow = const AuthApiException(
          code: 'IDENTITY_020',
          message: 'Session list unavailable.',
        );
      final notifier = _AuthedNotifier();
      await tester.pumpWidget(
          _wrap(const SessionsScreen(), notifier: notifier, api: api));
      await tester.pumpAndSettle();

      expect(find.text('Session list unavailable.'), findsOneWidget);
      expect(find.text('Retry'), findsOneWidget);
    });

    testWidgets('revoke button calls revokeSession with session id',
        (tester) async {
      final api = _StubApi()
        ..sessions = [
          _session(id: 'ses-1', isCurrent: true),
          _session(id: 'ses-2', deviceName: 'Other Device'),
        ];
      final notifier = _AuthedNotifier();
      await tester.pumpWidget(
          _wrap(const SessionsScreen(), notifier: notifier, api: api));
      await tester.pumpAndSettle();

      await tester.tap(find.byIcon(Icons.logout));
      await tester.pump();

      expect(api.revokedSessionId, 'ses-2');
    });

    testWidgets('loading indicator shown during fetch', (tester) async {
      // Use a never-completing API so the loading state persists.
      final api = _NeverResolvingApi();
      final notifier = _AuthedNotifier();
      await tester.pumpWidget(
          _wrap(const SessionsScreen(), notifier: notifier, api: api));
      await tester.pump();

      expect(find.byType(CircularProgressIndicator), findsOneWidget);
    });

    testWidgets('session tile has Semantics label', (tester) async {
      final api = _StubApi()
        ..sessions = [
          _session(id: 'ses-1', isCurrent: true, deviceName: 'My Phone'),
        ];
      final notifier = _AuthedNotifier();
      await tester.pumpWidget(
          _wrap(const SessionsScreen(), notifier: notifier, api: api));
      await tester.pumpAndSettle();

      expect(
        find.byWidgetPredicate(
          (w) =>
              w is Semantics &&
              (w.properties.label?.contains('My Phone') ?? false),
        ),
        findsWidgets,
      );
    });
  });
}
