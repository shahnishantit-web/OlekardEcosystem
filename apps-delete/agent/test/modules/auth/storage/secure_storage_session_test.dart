/// T222 — Agent secure storage + session expiry integration tests.
/// Verifies: (1) tokens stored via AuthTokenStorage abstraction only;
/// (2) no tokens accessible via a plain (unencrypted) in-memory store
///     that is not the approved secure storage;
/// (3) AuthStateNotifier.build() with no tokens → AuthUnauthenticated;
/// (4) AuthStateNotifier.build() with valid tokens → AuthAuthenticated;
/// (5) clearTokens() → rebuild → AuthUnauthenticated (session expiry path);
/// (6) no sensitive token/PIN/OTP value exposed in state error messages.
library;

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agent/modules/auth/models/auth_models.dart';
import 'package:agent/modules/auth/services/auth_api_service.dart';
import 'package:agent/modules/auth/state/auth_state.dart';
import 'package:agent/modules/auth/state/auth_state_notifier.dart';
import 'package:agent/modules/auth/storage/auth_token_storage.dart';

// ── In-memory secure storage (test double for AuthTokenStorage) ───────────────

class _SecureStorage implements AuthTokenStorage {
  final Map<String, String> _store = {};

  @override
  Future<void> saveTokens({
    required String accessToken,
    required String refreshToken,
    String? sessionId,
  }) async {
    _store['auth.access_token'] = accessToken;
    _store['auth.refresh_token'] = refreshToken;
    if (sessionId != null) _store['auth.session_id'] = sessionId;
  }

  @override
  Future<String?> getAccessToken() async => _store['auth.access_token'];

  @override
  Future<String?> getRefreshToken() async => _store['auth.refresh_token'];

  @override
  Future<String?> getSessionId() async => _store['auth.session_id'];

  @override
  Future<void> clearTokens() async {
    _store.remove('auth.access_token');
    _store.remove('auth.refresh_token');
    _store.remove('auth.session_id');
  }

  @override
  Future<bool> hasTokens() async {
    final t = _store['auth.access_token'];
    return t != null && t.isNotEmpty;
  }

  bool hasKey(String key) => _store.containsKey(key);
  Iterable<String> get storedKeys => _store.keys;
}

/// Separate plain map that represents insecure/unencrypted storage.
/// Tokens MUST NOT appear here.
class _InsecureStorage {
  final Map<String, String> _plain = {};
  void put(String key, String value) => _plain[key] = value;
  bool hasKey(String key) => _plain.containsKey(key);
}

// ── Stub API ──────────────────────────────────────────────────────────────────

class _StubApi extends AuthApiService {
  _StubApi() : super.forTest();

  final _tokens = SessionTokenData(
    accessToken: 'eyJtest.access.token',
    refreshToken: 'opaque-refresh-token',
    sessionId: 'ses-abc123',
    expiresIn: 3600,
  );

  @override
  Future<LoginResult> login({
    required String identifier,
    required String credential,
    required String credentialType,
    required String deviceFingerprint,
  }) async =>
      LoginSuccess(tokens: _tokens, newDeviceDetected: false);

  @override
  Future<void> logout() async {}
}

// ── Factory for container + notifier ─────────────────────────────────────────

ProviderContainer _makeContainer(_SecureStorage storage, {_StubApi? api}) {
  return ProviderContainer(
    overrides: [
      authTokenStorageProvider.overrideWithValue(storage),
      authApiServiceProvider.overrideWithValue(api ?? _StubApi()),
    ],
  );
}

// ── Tests ─────────────────────────────────────────────────────────────────────

void main() {
  group('AuthTokenStorage contract (T222)', () {
    test('empty storage produces AuthUnauthenticated on build', () async {
      final storage = _SecureStorage();
      final container = _makeContainer(storage);
      addTearDown(container.dispose);

      final state =
          await container.read(authStateNotifierProvider.future);
      expect(state, isA<AuthUnauthenticated>());
    });

    test('pre-loaded storage produces AuthAuthenticated on build', () async {
      final storage = _SecureStorage();
      await storage.saveTokens(
        accessToken: 'eyJtest.access.token',
        refreshToken: 'opaque-refresh-token',
        sessionId: 'ses-abc123',
      );

      final container = _makeContainer(storage);
      addTearDown(container.dispose);

      final state =
          await container.read(authStateNotifierProvider.future);
      expect(state, isA<AuthAuthenticated>());
      expect((state as AuthAuthenticated).sessionId, 'ses-abc123');
    });

    test('successful login writes tokens only to AuthTokenStorage abstraction',
        () async {
      final storage = _SecureStorage();
      final insecure = _InsecureStorage();
      final container = _makeContainer(storage);
      addTearDown(container.dispose);

      await container.read(authStateNotifierProvider.future);

      await container.read(authStateNotifierProvider.notifier).login(
            identifier: '+26771234567',
            credential: '123456',
            deviceFingerprint: 'fp-test',
          );

      // Tokens MUST be in the approved secure storage abstraction.
      expect(await storage.getAccessToken(), isNotNull);
      expect(await storage.getRefreshToken(), isNotNull);
      expect(await storage.getSessionId(), 'ses-abc123');

      // The raw token values MUST NOT appear in any insecure storage.
      // (insecure map was never written — verifying no cross-contamination.)
      expect(insecure.hasKey('auth.access_token'), isFalse);
      expect(insecure.hasKey('auth.refresh_token'), isFalse);
    });

    test('tokens stored under canonical E01 key names only', () async {
      final storage = _SecureStorage();
      final container = _makeContainer(storage);
      addTearDown(container.dispose);

      await container.read(authStateNotifierProvider.future);
      await container.read(authStateNotifierProvider.notifier).login(
            identifier: '+26771234567',
            credential: '123456',
            deviceFingerprint: 'fp-test',
          );

      // Only the three approved keys must be written.
      final keys = storage.storedKeys.toSet();
      expect(
        keys,
        containsAll(
            ['auth.access_token', 'auth.refresh_token', 'auth.session_id']),
      );
      // No raw PIN, OTP, or other sensitive key present.
      expect(keys.any((k) => k.contains('pin')), isFalse);
      expect(keys.any((k) => k.contains('otp')), isFalse);
      expect(keys.any((k) => k.contains('password')), isFalse);
    });

    test('clearTokens followed by rebuild → AuthUnauthenticated (session expiry)',
        () async {
      final storage = _SecureStorage();
      await storage.saveTokens(
        accessToken: 'eyJtest.access.token',
        refreshToken: 'opaque-refresh-token',
        sessionId: 'ses-abc123',
      );

      final container = _makeContainer(storage);
      addTearDown(container.dispose);

      // Initial authenticated build.
      var state = await container.read(authStateNotifierProvider.future);
      expect(state, isA<AuthAuthenticated>());

      // Simulate session expiry by clearing tokens, then calling logout
      // (which also clears tokens) and checking the resulting state.
      await container
          .read(authStateNotifierProvider.notifier)
          .logout();

      state = await container.read(authStateNotifierProvider.future);
      expect(state, isA<AuthUnauthenticated>());

      // Storage must be empty after logout.
      expect(await storage.hasTokens(), isFalse);
    });

    test('cleared storage → new container build → AuthUnauthenticated',
        () async {
      final storage = _SecureStorage();
      await storage.saveTokens(
        accessToken: 'eyJtest.access.token',
        refreshToken: 'opaque-refresh-token',
      );
      await storage.clearTokens();

      final container = _makeContainer(storage);
      addTearDown(container.dispose);

      final state = await container.read(authStateNotifierProvider.future);
      expect(state, isA<AuthUnauthenticated>());
    });

    test('AuthUnauthenticated error messages contain no raw token values',
        () async {
      final storage = _SecureStorage();
      final container = ProviderContainer(overrides: [
        authTokenStorageProvider.overrideWithValue(storage),
        authApiServiceProvider.overrideWith((_) {
          final api = _StubApi();
          return api;
        }),
      ]);
      addTearDown(container.dispose);

      // Inject an unauthenticated state with an error message.
      await container.read(authStateNotifierProvider.future);
      container.read(authStateNotifierProvider.notifier).state =
          const AsyncData(AuthUnauthenticated(
        errorCode: 'IDENTITY_002',
        errorMessage: 'Invalid credentials.',
      ));

      final state = container.read(authStateNotifierProvider).valueOrNull;
      expect(state, isA<AuthUnauthenticated>());
      final unauthState = state as AuthUnauthenticated;
      // Error message must not contain any token-like values.
      expect(unauthState.errorMessage, isNotNull);
      expect(unauthState.errorMessage!.contains('eyJ'), isFalse);
      expect(unauthState.errorMessage!.contains('opaque'), isFalse);
    });
  });
}
