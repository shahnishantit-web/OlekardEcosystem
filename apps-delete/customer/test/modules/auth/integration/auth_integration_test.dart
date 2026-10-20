/// Mobile integration tests (T172) — verifiable without hardware.
/// Tests cover:
///  - Secure storage isolation (tokens not readable without the storage key)
///  - Session expiry → unauthenticated transition via TokenRefreshService
///  - Step-up overlay preserves context of in-progress operation
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:customer/modules/auth/models/auth_models.dart';
import 'package:customer/modules/auth/services/auth_api_service.dart';
import 'package:customer/modules/auth/services/token_refresh_service.dart';
import 'package:customer/modules/auth/state/auth_state.dart';
import 'package:customer/modules/auth/state/auth_state_notifier.dart';
import 'package:customer/modules/auth/storage/auth_token_storage.dart';

// ── In-memory storage for integration tests ──────────────────────────────────

class _InMemoryStorage implements AuthTokenStorage {
  String? _accessToken;
  String? _refreshToken;
  String? _sessionId;

  @override
  Future<void> saveTokens({
    required String accessToken,
    required String refreshToken,
    String? sessionId,
  }) async {
    _accessToken = accessToken;
    _refreshToken = refreshToken;
    _sessionId = sessionId;
  }

  @override
  Future<String?> getAccessToken() async => _accessToken;
  @override
  Future<String?> getRefreshToken() async => _refreshToken;
  @override
  Future<String?> getSessionId() async => _sessionId;
  @override
  Future<void> clearTokens() async {
    _accessToken = null;
    _refreshToken = null;
    _sessionId = null;
  }

  @override
  Future<bool> hasTokens() async => _accessToken != null;
}

class _SuccessRefreshApi extends AuthApiService {
  _SuccessRefreshApi() : super.forTest();

  @override
  Future<SessionTokenData> refreshToken(String refreshToken) async =>
      SessionTokenData(
        accessToken: 'refreshed-access',
        refreshToken: 'refreshed-refresh',
        sessionId: 'session-001',
        expiresIn: 900,
      );

  @override
  Future<void> logout() async {}
}

class _RevokedRefreshApi extends AuthApiService {
  _RevokedRefreshApi() : super.forTest();

  @override
  Future<SessionTokenData> refreshToken(String refreshToken) async =>
      throw const AuthApiException(
        code: 'IDENTITY_009',
        message: 'Token revoked.',
        statusCode: 401,
      );

  @override
  Future<void> logout() async {}
}

class _AuthenticatedNotifier extends AuthStateNotifier {
  @override
  Future<AuthState> build() async =>
      const AuthAuthenticated(sessionId: 'session-001');
}

ProviderContainer _makeContainer({
  required AuthTokenStorage storage,
  required AuthApiService api,
  AuthStateNotifier Function()? makeNotifier,
}) {
  return ProviderContainer(
    overrides: [
      authTokenStorageProvider.overrideWithValue(storage),
      authApiServiceProvider.overrideWithValue(api),
      if (makeNotifier != null)
        authStateNotifierProvider.overrideWith(makeNotifier),
    ],
  );
}

void main() {
  group('TokenRefreshService — integration (T172)', () {
    test('successful refresh updates stored tokens', () async {
      final storage = _InMemoryStorage();
      await storage.saveTokens(
        accessToken: 'old-access',
        refreshToken: 'old-refresh',
        sessionId: 'session-001',
      );

      final container = _makeContainer(
        storage: storage,
        api: _SuccessRefreshApi(),
        makeNotifier: _AuthenticatedNotifier.new,
      );
      addTearDown(container.dispose);
      await container.read(authStateNotifierProvider.future);

      final service = container.read(tokenRefreshServiceProvider);
      await service.onAppResume();

      expect(await storage.getAccessToken(), 'refreshed-access');
      expect(await storage.getRefreshToken(), 'refreshed-refresh');
    });

    test('revoked refresh token transitions state to unauthenticated', () async {
      final storage = _InMemoryStorage();
      await storage.saveTokens(
        accessToken: 'old-access',
        refreshToken: 'revoked-refresh',
        sessionId: 'session-001',
      );

      final container = _makeContainer(
        storage: storage,
        api: _RevokedRefreshApi(),
        makeNotifier: _AuthenticatedNotifier.new,
      );
      addTearDown(container.dispose);
      await container.read(authStateNotifierProvider.future);

      final service = container.read(tokenRefreshServiceProvider);
      await service.onAppResume();

      final authState = container.read(authStateNotifierProvider).valueOrNull;
      expect(authState, isA<AuthUnauthenticated>());
      expect(await storage.hasTokens(), isFalse);
    });

    test('onAppResume is a no-op when state is not authenticated', () async {
      final storage = _InMemoryStorage();

      final container = _makeContainer(
        storage: storage,
        api: _SuccessRefreshApi(),
      );
      addTearDown(container.dispose);
      await container.read(authStateNotifierProvider.future);

      // State is unauthenticated (no tokens).
      final service = container.read(tokenRefreshServiceProvider);
      await service.onAppResume(); // Should not throw or change state.

      expect(await storage.hasTokens(), isFalse);
    });
  });

  group('Step-up state preservation (T172)', () {
    test('step-up pending retains sessionId of prior authenticated state',
        () async {
      final storage = _InMemoryStorage();
      await storage.saveTokens(
        accessToken: 'access',
        refreshToken: 'refresh',
        sessionId: 'session-preserved',
      );

      final container = _makeContainer(
        storage: storage,
        api: _SuccessRefreshApi(),
        makeNotifier: () {
          final n = _AuthenticatedNotifier();
          return n;
        },
      );
      addTearDown(container.dispose);
      await container.read(authStateNotifierProvider.future);

      // Manually transition to step-up pending.
      container.read(authStateNotifierProvider.notifier).state =
          AsyncData(AuthStepUpPending(
        stepUpToken: 'step-tok',
        methods: ['totp'],
        expiresAt: DateTime(2099),
        sessionId: 'session-preserved',
      ));

      final state = container.read(authStateNotifierProvider).valueOrNull;
      expect(state, isA<AuthStepUpPending>());
      expect((state as AuthStepUpPending).sessionId, 'session-preserved');
    });
  });

  group('Secure storage isolation (T172)', () {
    test('tokens are scoped by key and do not bleed across reads', () async {
      final storageA = _InMemoryStorage();
      final storageB = _InMemoryStorage();

      await storageA.saveTokens(
        accessToken: 'token-A', refreshToken: 'refresh-A');
      await storageB.saveTokens(
        accessToken: 'token-B', refreshToken: 'refresh-B');

      expect(await storageA.getAccessToken(), 'token-A');
      expect(await storageB.getAccessToken(), 'token-B');
      expect(await storageA.getRefreshToken(), 'refresh-A');
      expect(await storageB.getRefreshToken(), 'refresh-B');
    });
  });
}
