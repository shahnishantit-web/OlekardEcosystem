import '../entities/device.dart';

abstract class DeviceRepository {
  Future<({List<Device> devices, int total, int currentPage, int lastPage})> listDevices({
    int page = 1,
    int perPage = 20,
  });

  Future<Device> getDevice(String deviceId);

  Future<String> generateFingerprint({
    required String platform,
    required Map<String, dynamic> attributes,
  });

  Future<({String deviceId, String status, String? challengeId})> registerDevice({
    required String deviceName,
    required String platform,
    required String fingerprintHash,
    required String idempotencyKey,
  });

  Future<void> trustDevice(String deviceId, {String? reason});

  Future<void> removeDevice(String deviceId);

  Future<void> blockDevice(String deviceId, {String? reason});

  Future<void> acknowledgeNewDevice(String deviceId, {required bool acknowledged});
}
