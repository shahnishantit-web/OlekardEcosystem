enum DeviceStatus {
  pendingVerification,
  verified,
  trusted,
  blocked,
  revoked;

  static DeviceStatus fromJson(String value) {
    return switch (value) {
      'pending_verification' => pendingVerification,
      'verified' => verified,
      'trusted' => trusted,
      'blocked' => blocked,
      'revoked' => revoked,
      _ => revoked,
    };
  }

  String get label => switch (this) {
    pendingVerification => 'Pending Verification',
    verified => 'Verified',
    trusted => 'Trusted',
    blocked => 'Blocked',
    revoked => 'Revoked',
  };

  bool get isActive => this == trusted || this == verified || this == pendingVerification;
}

class Device {
  final String id;
  final String userId;
  final String deviceName;
  final String platform;
  final DeviceStatus status;
  final DateTime? trustedAt;
  final DateTime? blockedAt;
  final String? blockedReason;
  final DateTime? lastSeenAt;
  final DateTime createdAt;

  const Device({
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
}
