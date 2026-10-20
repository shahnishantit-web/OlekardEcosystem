import 'package:flutter_secure_storage/flutter_secure_storage.dart';

class SecureStorageService {
  static const _androidOptions = AndroidOptions(
    encryptedSharedPreferences: true,
  );
  static const _iosOptions = IOSOptions(
    accessibility: KeychainAccessibility.unlocked_this_device,
  );

  const SecureStorageService();

  FlutterSecureStorage get _storage => const FlutterSecureStorage(
        aOptions: _androidOptions,
        iOptions: _iosOptions,
      );

  Future<String?> readAccessToken() => _storage.read(key: 'access_token');
  Future<void> writeAccessToken(String token) =>
      _storage.write(key: 'access_token', value: token);

  Future<String?> readRefreshToken() => _storage.read(key: 'refresh_token');
  Future<void> writeRefreshToken(String token) =>
      _storage.write(key: 'refresh_token', value: token);

  Future<String?> readPinHash() => _storage.read(key: 'pin_hash');
  Future<void> writePinHash(String hash) =>
      _storage.write(key: 'pin_hash', value: hash);

  Future<String?> readDeviceId() => _storage.read(key: 'device_id');
  Future<void> writeDeviceId(String deviceId) =>
      _storage.write(key: 'device_id', value: deviceId);

  Future<void> deleteAll() => _storage.deleteAll();
}
