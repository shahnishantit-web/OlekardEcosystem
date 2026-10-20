import 'package:dio/dio.dart';
import '../../../../../core/api/api_client.dart';
import '../models/device_dto.dart';

class DeviceRemoteDataSource {
  final ApiClient _client;

  const DeviceRemoteDataSource(this._client);

  Future<DeviceListDto> listDevices({int page = 1, int perPage = 20}) async {
    final response = await _client.dio.get<Map<String, dynamic>>(
      '/v1/devices',
      queryParameters: {'page': page, 'per_page': perPage},
    );
    final body = response.data!;
    return DeviceListDto.fromJson(body['data'] as Map<String, dynamic>);
  }

  Future<DeviceDto> getDevice(String deviceId) async {
    final response = await _client.dio.get<Map<String, dynamic>>(
      '/v1/devices/$deviceId',
    );
    final body = response.data!;
    return DeviceDto.fromJson(body['data'] as Map<String, dynamic>);
  }

  Future<void> trustDevice(String deviceId, {String? reason}) async {
    await _client.dio.post<void>(
      '/v1/devices/$deviceId/trust',
      data: {'reason': reason},
      options: Options(headers: {'Idempotency-Key': _generateIdempotencyKey('trust', deviceId)}),
    );
  }

  Future<void> removeDevice(String deviceId) async {
    await _client.dio.delete<void>(
      '/v1/devices/$deviceId',
      options: Options(headers: {'Idempotency-Key': _generateIdempotencyKey('remove', deviceId)}),
    );
  }

  Future<String> generateFingerprint({
    required String platform,
    required Map<String, dynamic> attributes,
  }) async {
    final response = await _client.dio.post<Map<String, dynamic>>(
      '/v1/devices/fingerprint',
      data: {'platform': platform, 'attributes': attributes},
    );
    final body = response.data!;
    return (body['data'] as Map<String, dynamic>)['fingerprint_hash'] as String;
  }

  Future<({String deviceId, String status, String? challengeId})> registerDevice({
    required String deviceName,
    required String platform,
    required String fingerprintHash,
    required String idempotencyKey,
  }) async {
    final response = await _client.dio.post<Map<String, dynamic>>(
      '/v1/devices',
      data: {
        'device_name':      deviceName,
        'platform':         platform,
        'fingerprint_hash': fingerprintHash,
      },
      options: Options(headers: {'Idempotency-Key': idempotencyKey}),
    );
    final data = (response.data!['data'] as Map<String, dynamic>);
    return (
      deviceId:    data['device_id'] as String,
      status:      data['status'] as String,
      challengeId: data['challenge_id'] as String?,
    );
  }

  Future<void> blockDevice(String deviceId, {String? reason}) async {
    await _client.dio.post<void>(
      '/v1/devices/$deviceId/block',
      data: {'reason': reason},
      options: Options(headers: {'Idempotency-Key': _generateIdempotencyKey('block', deviceId)}),
    );
  }

  Future<void> acknowledgeNewDevice(String deviceId, {required bool acknowledged}) async {
    await _client.dio.post<void>(
      '/v1/devices/$deviceId/acknowledge',
      data: {'acknowledged': acknowledged},
      options: Options(headers: {'Idempotency-Key': _generateIdempotencyKey('ack', deviceId)}),
    );
  }

  String _generateIdempotencyKey(String action, String deviceId) {
    return '$action-$deviceId-${DateTime.now().millisecondsSinceEpoch}';
  }
}
