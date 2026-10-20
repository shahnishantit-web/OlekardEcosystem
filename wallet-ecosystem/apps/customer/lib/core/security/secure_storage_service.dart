import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:shared_preferences/shared_preferences.dart';

class SecureStorageService {
  // v11 uses Android KeyStore-backed AES-GCM encryption by default.
  static const _androidOptions = AndroidOptions();
  static const _iosOptions = IOSOptions(
    accessibility: KeychainAccessibility.unlocked_this_device,
  );

  const SecureStorageService();

  FlutterSecureStorage get _storage => const FlutterSecureStorage(
        aOptions: _androidOptions,
        iOptions: _iosOptions,
      );

  Future<String?> readAccessToken() async {
    if (kIsWeb) {
      final prefs = await SharedPreferences.getInstance();
      return prefs.getString('access_token');
    }
    try {
      return await _storage.read(key: 'access_token');
    } catch (_) {
      return null;
    }
  }

  Future<void> writeAccessToken(String token) async {
    if (kIsWeb) {
      final prefs = await SharedPreferences.getInstance();
      await prefs.setString('access_token', token);
      return;
    }
    try {
      await _storage.write(key: 'access_token', value: token);
    } catch (_) {}
  }

  Future<String?> readRefreshToken() async {
    if (kIsWeb) {
      final prefs = await SharedPreferences.getInstance();
      return prefs.getString('refresh_token');
    }
    try {
      return await _storage.read(key: 'refresh_token');
    } catch (_) {
      return null;
    }
  }

  Future<void> writeRefreshToken(String token) async {
    if (kIsWeb) {
      final prefs = await SharedPreferences.getInstance();
      await prefs.setString('refresh_token', token);
      return;
    }
    try {
      await _storage.write(key: 'refresh_token', value: token);
    } catch (_) {}
  }

  Future<String?> readPinHash() async {
    if (kIsWeb) {
      final prefs = await SharedPreferences.getInstance();
      return prefs.getString('pin_hash');
    }
    try {
      return await _storage.read(key: 'pin_hash');
    } catch (_) {
      return null;
    }
  }

  Future<void> writePinHash(String hash) async {
    if (kIsWeb) {
      final prefs = await SharedPreferences.getInstance();
      await prefs.setString('pin_hash', hash);
      return;
    }
    try {
      await _storage.write(key: 'pin_hash', value: hash);
    } catch (_) {}
  }

  Future<String?> readDeviceId() async {
    if (kIsWeb) {
      final prefs = await SharedPreferences.getInstance();
      return prefs.getString('device_id');
    }
    try {
      return await _storage.read(key: 'device_id');
    } catch (_) {
      return null;
    }
  }

  Future<void> writeDeviceId(String deviceId) async {
    if (kIsWeb) {
      final prefs = await SharedPreferences.getInstance();
      await prefs.setString('device_id', deviceId);
      return;
    }
    try {
      await _storage.write(key: 'device_id', value: deviceId);
    } catch (_) {}
  }

  Future<void> deleteAll() async {
    if (kIsWeb) {
      final prefs = await SharedPreferences.getInstance();
      await prefs.clear();
      return;
    }
    try {
      await _storage.deleteAll();
    } catch (_) {}
  }
}
