import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:shared_preferences/shared_preferences.dart';

class SecureStorageService {
  static const _eventOpsAccessTokenKey = 'event_ops_access_token';
  static const _eventOpsRefreshTokenKey = 'event_ops_refresh_token';
  static const _pendingFuneralInvitationKey =
      'pending_funeral_invitation_token';
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

  Future<String?> readEventOpsAccessToken() async {
    if (kIsWeb) {
      final prefs = await SharedPreferences.getInstance();
      return prefs.getString(_eventOpsAccessTokenKey);
    }
    try {
      return await _storage.read(key: _eventOpsAccessTokenKey);
    } catch (_) {
      return null;
    }
  }

  Future<void> writeEventOpsAccessToken(String token) async {
    if (kIsWeb) {
      final prefs = await SharedPreferences.getInstance();
      await prefs.setString(_eventOpsAccessTokenKey, token);
      return;
    }
    try {
      await _storage.write(key: _eventOpsAccessTokenKey, value: token);
    } catch (_) {}
  }

  Future<String?> readEventOpsRefreshToken() async {
    if (kIsWeb) {
      final prefs = await SharedPreferences.getInstance();
      return prefs.getString(_eventOpsRefreshTokenKey);
    }
    try {
      return await _storage.read(key: _eventOpsRefreshTokenKey);
    } catch (_) {
      return null;
    }
  }

  Future<void> writeEventOpsRefreshToken(String token) async {
    if (kIsWeb) {
      final prefs = await SharedPreferences.getInstance();
      await prefs.setString(_eventOpsRefreshTokenKey, token);
      return;
    }
    try {
      await _storage.write(key: _eventOpsRefreshTokenKey, value: token);
    } catch (_) {}
  }

  Future<void> deleteEventOpsAccessToken() async {
    if (kIsWeb) {
      final prefs = await SharedPreferences.getInstance();
      await prefs.remove(_eventOpsAccessTokenKey);
      await prefs.remove(_eventOpsRefreshTokenKey);
      return;
    }
    try {
      await _storage.delete(key: _eventOpsAccessTokenKey);
      await _storage.delete(key: _eventOpsRefreshTokenKey);
    } catch (_) {}
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

  Future<String?> readPendingFuneralInvitation() async {
    if (kIsWeb) {
      final prefs = await SharedPreferences.getInstance();
      return prefs.getString(_pendingFuneralInvitationKey);
    }
    try {
      return await _storage.read(key: _pendingFuneralInvitationKey);
    } catch (_) {
      return null;
    }
  }

  Future<void> writePendingFuneralInvitation(String token) async {
    if (kIsWeb) {
      final prefs = await SharedPreferences.getInstance();
      await prefs.setString(_pendingFuneralInvitationKey, token);
      return;
    }
    try {
      await _storage.write(key: _pendingFuneralInvitationKey, value: token);
    } catch (_) {}
  }

  Future<void> deletePendingFuneralInvitation() async {
    if (kIsWeb) {
      final prefs = await SharedPreferences.getInstance();
      await prefs.remove(_pendingFuneralInvitationKey);
      return;
    }
    try {
      await _storage.delete(key: _pendingFuneralInvitationKey);
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
