import 'package:device_info_plus/device_info_plus.dart';
import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';

import 'package:wallet_agent/core/security/secure_storage_service.dart';

class DeviceRegistrationService {
  final Dio _dio;
  final SecureStorageService _storage;
  final DeviceInfoPlugin _deviceInfo;

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

    final response = await _dio.post<Map<String, dynamic>>(
      '/v1/agent/devices',
      data: {
        'device_id': _generateDeviceIdentifier(),
        'platform': platform,
        'model': model,
        'os_version': osVersion,
      },
    );

    final deviceId = response.data!['id'] as String;
    await _storage.saveDeviceId(deviceId);
    return deviceId;
  }

  Future<void> registerPushToken(String pushToken) async {
    var deviceId = await _storage.getDeviceId();
    // Device not registered yet — register first then retry.
    deviceId ??= await registerDevice();

    final platform = defaultTargetPlatform == TargetPlatform.iOS
        ? 'ios'
        : 'android';

    await _dio.post<void>(
      '/v1/agent/devices/$deviceId/push-token',
      data: {
        'push_token': pushToken,
        'platform': platform,
      },
    );
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
    return ('unknown', 'unknown', 'unknown');
  }

  String _generateDeviceIdentifier() {
    // Deterministic per-install identifier derived from timestamp + random.
    // A real implementation would use a persisted UUID stored on first install.
    return DateTime.now().millisecondsSinceEpoch.toRadixString(16);
  }
}
