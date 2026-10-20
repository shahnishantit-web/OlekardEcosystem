import 'package:flutter_secure_storage/flutter_secure_storage.dart';

const _kAccessToken = 'agent_access_token';
const _kRefreshToken = 'agent_refresh_token';
const _kPinHash = 'agent_pin_hash';
const _kDeviceId = 'agent_device_id';

class SecureStorageService {
  final FlutterSecureStorage _storage;

  SecureStorageService({FlutterSecureStorage? storage})
      : _storage = storage ??
            const FlutterSecureStorage(
              aOptions: AndroidOptions(encryptedSharedPreferences: true),
            );

  Future<void> saveAccessToken(String token) =>
      _storage.write(key: _kAccessToken, value: token);

  Future<String?> getAccessToken() => _storage.read(key: _kAccessToken);

  Future<void> saveRefreshToken(String token) =>
      _storage.write(key: _kRefreshToken, value: token);

  Future<String?> getRefreshToken() => _storage.read(key: _kRefreshToken);

  Future<void> savePinHash(String hash) =>
      _storage.write(key: _kPinHash, value: hash);

  Future<String?> getPinHash() => _storage.read(key: _kPinHash);

  Future<void> saveDeviceId(String deviceId) =>
      _storage.write(key: _kDeviceId, value: deviceId);

  Future<String?> getDeviceId() => _storage.read(key: _kDeviceId);

  Future<void> clearAll() => _storage.deleteAll();

  Future<void> clearAuthTokens() async {
    await Future.wait([
      _storage.delete(key: _kAccessToken),
      _storage.delete(key: _kRefreshToken),
    ]);
  }
}
