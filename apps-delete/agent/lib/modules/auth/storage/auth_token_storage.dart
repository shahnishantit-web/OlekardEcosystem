import 'package:flutter_secure_storage/flutter_secure_storage.dart';

const _kAccessToken = 'auth.access_token';
const _kRefreshToken = 'auth.refresh_token';
const _kSessionId = 'auth.session_id';

/// Contract for token persistence. Implementations MUST use hardware-backed
/// secure storage (iOS Keychain / Android Keystore). SharedPreferences is
/// explicitly prohibited by BR-009.
abstract class AuthTokenStorage {
  Future<void> saveTokens({
    required String accessToken,
    required String refreshToken,
    String? sessionId,
  });

  Future<String?> getAccessToken();
  Future<String?> getRefreshToken();
  Future<String?> getSessionId();
  Future<void> clearTokens();
  Future<bool> hasTokens();
}

/// Production implementation backed by flutter_secure_storage.
/// iOS: Keychain with `first_unlock_this_device` accessibility.
/// Android: EncryptedSharedPreferences (AES256).
class SecureAuthTokenStorage implements AuthTokenStorage {
  SecureAuthTokenStorage()
      : _storage = const FlutterSecureStorage(
          aOptions: AndroidOptions(encryptedSharedPreferences: true),
          iOptions: IOSOptions(
            accessibility: KeychainAccessibility.first_unlock_this_device,
          ),
        );

  final FlutterSecureStorage _storage;

  @override
  Future<void> saveTokens({
    required String accessToken,
    required String refreshToken,
    String? sessionId,
  }) async {
    await Future.wait([
      _storage.write(key: _kAccessToken, value: accessToken),
      _storage.write(key: _kRefreshToken, value: refreshToken),
      if (sessionId != null)
        _storage.write(key: _kSessionId, value: sessionId),
    ]);
  }

  @override
  Future<String?> getAccessToken() => _storage.read(key: _kAccessToken);

  @override
  Future<String?> getRefreshToken() => _storage.read(key: _kRefreshToken);

  @override
  Future<String?> getSessionId() => _storage.read(key: _kSessionId);

  @override
  Future<void> clearTokens() async {
    await Future.wait([
      _storage.delete(key: _kAccessToken),
      _storage.delete(key: _kRefreshToken),
      _storage.delete(key: _kSessionId),
    ]);
  }

  @override
  Future<bool> hasTokens() async {
    final token = await _storage.read(key: _kAccessToken);
    return token != null && token.isNotEmpty;
  }
}
