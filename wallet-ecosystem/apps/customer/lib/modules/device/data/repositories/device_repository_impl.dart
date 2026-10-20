import '../../domain/entities/device.dart';
import '../../domain/repositories/device_repository.dart';
import 'device_remote_data_source.dart';

class DeviceRepositoryImpl implements DeviceRepository {
  final DeviceRemoteDataSource _remote;

  const DeviceRepositoryImpl(this._remote);

  @override
  Future<({List<Device> devices, int total, int currentPage, int lastPage})> listDevices({
    int page = 1,
    int perPage = 20,
  }) async {
    final dto = await _remote.listDevices(page: page, perPage: perPage);
    return (
      devices:     dto.data.map((d) => d.toDomain()).toList(),
      total:       dto.meta.total,
      currentPage: dto.meta.currentPage,
      lastPage:    dto.meta.lastPage,
    );
  }

  @override
  Future<Device> getDevice(String deviceId) async {
    final dto = await _remote.getDevice(deviceId);
    return dto.toDomain();
  }

  @override
  Future<String> generateFingerprint({
    required String platform,
    required Map<String, dynamic> attributes,
  }) {
    return _remote.generateFingerprint(platform: platform, attributes: attributes);
  }

  @override
  Future<({String deviceId, String status, String? challengeId})> registerDevice({
    required String deviceName,
    required String platform,
    required String fingerprintHash,
    required String idempotencyKey,
  }) {
    return _remote.registerDevice(
      deviceName:      deviceName,
      platform:        platform,
      fingerprintHash: fingerprintHash,
      idempotencyKey:  idempotencyKey,
    );
  }

  @override
  Future<void> trustDevice(String deviceId, {String? reason}) {
    return _remote.trustDevice(deviceId, reason: reason);
  }

  @override
  Future<void> removeDevice(String deviceId) {
    return _remote.removeDevice(deviceId);
  }

  @override
  Future<void> blockDevice(String deviceId, {String? reason}) {
    return _remote.blockDevice(deviceId);
  }

  @override
  Future<void> acknowledgeNewDevice(String deviceId, {required bool acknowledged}) {
    return _remote.acknowledgeNewDevice(deviceId, acknowledged: acknowledged);
  }
}
