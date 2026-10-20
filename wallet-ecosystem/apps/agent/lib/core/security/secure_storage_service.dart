import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:shared_preferences/shared_preferences.dart';

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

  Future<void> saveAccessToken(String token) async {
    if (kIsWeb) {
      final prefs = await SharedPreferences.getInstance();
      await prefs.setString(_kAccessToken, token);
      return;
    }
    try {
      await _storage.write(key: _kAccessToken, value: token);
    } catch (e) {
      debugPrint('SecureStorage write error: $e');
    }
  }

  Future<String?> getAccessToken() async {
    if (kIsWeb) {
      final prefs = await SharedPreferences.getInstance();
      return prefs.getString(_kAccessToken);
    }
    try {
      return await _storage.read(key: _kAccessToken);
    } catch (e) {
      debugPrint('SecureStorage read error: $e');
      return null;
    }
  }

  Future<void> saveRefreshToken(String token) async {
    if (kIsWeb) {
      final prefs = await SharedPreferences.getInstance();
      await prefs.setString(_kRefreshToken, token);
      return;
    }
    try {
      await _storage.write(key: _kRefreshToken, value: token);
    } catch (e) {
      debugPrint('SecureStorage write error: $e');
    }
  }

  Future<String?> getRefreshToken() async {
    if (kIsWeb) {
      final prefs = await SharedPreferences.getInstance();
      return prefs.getString(_kRefreshToken);
    }
    try {
      return await _storage.read(key: _kRefreshToken);
    } catch (e) {
      debugPrint('SecureStorage read error: $e');
      return null;
    }
  }

  Future<void> savePinHash(String hash) async {
    if (kIsWeb) {
      final prefs = await SharedPreferences.getInstance();
      await prefs.setString(_kPinHash, hash);
      return;
    }
    try {
      await _storage.write(key: _kPinHash, value: hash);
    } catch (e) {
      debugPrint('SecureStorage write error: $e');
    }
  }

  Future<String?> getPinHash() async {
    if (kIsWeb) {
      final prefs = await SharedPreferences.getInstance();
      return prefs.getString(_kPinHash);
    }
    try {
      return await _storage.read(key: _kPinHash);
    } catch (e) {
      debugPrint('SecureStorage read error: $e');
      return null;
    }
  }

  Future<void> saveDeviceId(String deviceId) async {
    if (kIsWeb) {
      final prefs = await SharedPreferences.getInstance();
      await prefs.setString(_kDeviceId, deviceId);
      return;
    }
    try {
      await _storage.write(key: _kDeviceId, value: deviceId);
    } catch (e) {
      debugPrint('SecureStorage write error: $e');
    }
  }

  Future<String?> getDeviceId() async {
    if (kIsWeb) {
      final prefs = await SharedPreferences.getInstance();
      return prefs.getString(_kDeviceId);
    }
    try {
      return await _storage.read(key: _kDeviceId);
    } catch (e) {
      debugPrint('SecureStorage read error: $e');
      return null;
    }
  }

  Future<void> clearAll() async {
    if (kIsWeb) {
      final prefs = await SharedPreferences.getInstance();
      await prefs.clear();
      return;
    }
    try {
      await _storage.deleteAll();
    } catch (e) {
      debugPrint('SecureStorage clear error: $e');
    }
  }

  Future<void> clearAuthTokens() async {
    if (kIsWeb) {
      final prefs = await SharedPreferences.getInstance();
      await prefs.remove(_kAccessToken);
      await prefs.remove(_kRefreshToken);
      return;
    }
    try {
      await Future.wait([
        _storage.delete(key: _kAccessToken),
        _storage.delete(key: _kRefreshToken),
      ]);
    } catch (e) {
      debugPrint('SecureStorage clear error: $e');
    }
  }
}
