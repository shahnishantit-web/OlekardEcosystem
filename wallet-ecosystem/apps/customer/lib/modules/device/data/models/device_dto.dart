import '../../domain/entities/device.dart';

class DeviceDto {
  final String id;
  final String userId;
  final String deviceName;
  final String platform;
  final String status;
  final String? trustedAt;
  final String? blockedAt;
  final String? blockedReason;
  final String? lastSeenAt;
  final String createdAt;

  const DeviceDto({
    required this.id,
    required this.userId,
    required this.deviceName,
    required this.platform,
    required this.status,
    this.trustedAt,
    this.blockedAt,
    this.blockedReason,
    this.lastSeenAt,
    required this.createdAt,
  });

  factory DeviceDto.fromJson(Map<String, dynamic> json) {
    return DeviceDto(
      id:            json['id'] as String,
      userId:        json['user_id'] as String,
      deviceName:    json['device_name'] as String,
      platform:      json['platform'] as String,
      status:        json['status'] as String,
      trustedAt:     json['trusted_at'] as String?,
      blockedAt:     json['blocked_at'] as String?,
      blockedReason: json['blocked_reason'] as String?,
      lastSeenAt:    json['last_seen_at'] as String?,
      createdAt:     json['created_at'] as String,
    );
  }

  Device toDomain() {
    return Device(
      id:            id,
      userId:        userId,
      deviceName:    deviceName,
      platform:      platform,
      status:        DeviceStatus.fromJson(status),
      trustedAt:     trustedAt != null ? DateTime.tryParse(trustedAt!) : null,
      blockedAt:     blockedAt != null ? DateTime.tryParse(blockedAt!) : null,
      blockedReason: blockedReason,
      lastSeenAt:    lastSeenAt != null ? DateTime.tryParse(lastSeenAt!) : null,
      createdAt:     DateTime.parse(createdAt),
    );
  }
}

class DeviceListDto {
  final List<DeviceDto> data;
  final DeviceListMetaDto meta;

  const DeviceListDto({required this.data, required this.meta});

  factory DeviceListDto.fromJson(Map<String, dynamic> json) {
    final dataJson = json['data'] as List<dynamic>;
    final metaJson = json['meta'] as Map<String, dynamic>;
    return DeviceListDto(
      data: dataJson.map((e) => DeviceDto.fromJson(e as Map<String, dynamic>)).toList(),
      meta: DeviceListMetaDto.fromJson(metaJson),
    );
  }
}

class DeviceListMetaDto {
  final int total;
  final int perPage;
  final int currentPage;
  final int lastPage;

  const DeviceListMetaDto({
    required this.total,
    required this.perPage,
    required this.currentPage,
    required this.lastPage,
  });

  factory DeviceListMetaDto.fromJson(Map<String, dynamic> json) {
    return DeviceListMetaDto(
      total:       json['total'] as int,
      perPage:     json['per_page'] as int,
      currentPage: json['current_page'] as int,
      lastPage:    json['last_page'] as int,
    );
  }
}
