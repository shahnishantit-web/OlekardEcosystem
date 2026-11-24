import 'package:device_info_plus/device_info_plus.dart';
import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:uuid/uuid.dart';

import 'package:wallet_agent/core/security/secure_storage_service.dart';

class DeviceRegistrationService {
  final Dio _dio;
  final SecureStorageService _storage;
  final DeviceInfoPlugin _deviceInfo;
  final _uuid = const Uuid();

  DeviceRegistrationService({
    required Dio dio,
    required SecureStorageService storage,
    DeviceInfoPlugin? deviceInfo,
  })  : _dio = dio,
        _storage = storage,
        _deviceInfo = deviceInfo ?? DeviceInfoPlugin();

  Future<String> registerDevice() async {
    final existingId = await _storage.getDeviceId();
    if (existingId != null) return existingId;

    final (platform, model, osVersion) = await _getDeviceAttributes();
    final fingerprint = await _storage.getDeviceFingerprint() ?? _uuid.v4();
    await _storage.saveDeviceFingerprint(fingerprint);

    final response = await _dio.post<Map<String, dynamic>>(
      '/v1/auth/devices/register',
      data: {
        'device_fingerprint': fingerprint,
        'device_name': '$model ($osVersion)',
        'platform': platform,
      },
    );

    final data =
        response.data?['data'] as Map<String, dynamic>? ?? response.data!;
    final deviceId = data['id'] as String;
    await _storage.saveDeviceId(deviceId);
    return deviceId;
  }

  Future<void> registerPushToken(String pushToken) async {
    throw UnsupportedError(
        'Push-token registration is not available in the current API.');
  }

  Future<(String platform, String model, String osVersion)>
      _getDeviceAttributes() async {
    if (defaultTargetPlatform == TargetPlatform.android) {
      final info = await _deviceInfo.androidInfo;
      return ('android', info.model, info.version.release);
    } else if (defaultTargetPlatform == TargetPlatform.iOS) {
      final info = await _deviceInfo.iosInfo;
      return ('ios', info.utsname.machine, info.systemVersion);
    }
    return ('web', 'Web browser', 'unknown');
  }
}
