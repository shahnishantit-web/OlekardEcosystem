import 'package:flutter_test/flutter_test.dart';
import 'package:customer/modules/auth/storage/auth_token_storage.dart';

// In-memory stub that mirrors SecureAuthTokenStorage without hardware backing.
class InMemoryAuthTokenStorage implements AuthTokenStorage {
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

void main() {
  late AuthTokenStorage storage;

  setUp(() {
    storage = InMemoryAuthTokenStorage();
  });

  group('AuthTokenStorage — contract tests (T159)', () {
    test('returns null before any tokens are saved', () async {
      expect(await storage.getAccessToken(), isNull);
      expect(await storage.getRefreshToken(), isNull);
      expect(await storage.getSessionId(), isNull);
      expect(await storage.hasTokens(), isFalse);
    });

    test('saves and retrieves access_token and refresh_token', () async {
      await storage.saveTokens(
        accessToken: 'eyJ.access',
        refreshToken: 'opaque-refresh',
        sessionId: 'session-uuid',
      );

      expect(await storage.getAccessToken(), 'eyJ.access');
      expect(await storage.getRefreshToken(), 'opaque-refresh');
      expect(await storage.getSessionId(), 'session-uuid');
      expect(await storage.hasTokens(), isTrue);
    });

    test('clearTokens removes all stored values', () async {
      await storage.saveTokens(
        accessToken: 'tok', refreshToken: 'rtok');
      await storage.clearTokens();

      expect(await storage.getAccessToken(), isNull);
      expect(await storage.getRefreshToken(), isNull);
      expect(await storage.hasTokens(), isFalse);
    });

    test('overwriting tokens replaces previous values', () async {
      await storage.saveTokens(
        accessToken: 'old-access', refreshToken: 'old-refresh');
      await storage.saveTokens(
        accessToken: 'new-access', refreshToken: 'new-refresh');

      expect(await storage.getAccessToken(), 'new-access');
      expect(await storage.getRefreshToken(), 'new-refresh');
    });

    test('sessionId is optional — storage works without it', () async {
      await storage.saveTokens(
        accessToken: 'tok', refreshToken: 'rtok');

      expect(await storage.getSessionId(), isNull);
    });
  });
}
