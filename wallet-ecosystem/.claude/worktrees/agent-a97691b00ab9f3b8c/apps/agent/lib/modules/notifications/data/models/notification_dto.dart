import 'package:wallet_agent/modules/notifications/domain/entities/app_notification.dart';

class AppNotificationDto {
  final String id;
  final String title;
  final String body;
  final String type;
  final bool isRead;
  final DateTime createdAt;
  final Map<String, dynamic>? data;

  const AppNotificationDto({
    required this.id,
    required this.title,
    required this.body,
    required this.type,
    required this.isRead,
    required this.createdAt,
    this.data,
  });

  factory AppNotificationDto.fromJson(Map<String, dynamic> json) {
    return AppNotificationDto(
      id: json['id'] as String,
      title: json['title'] as String,
      body: json['body'] as String,
      type: json['type'] as String,
      isRead: json['is_read'] as bool,
      createdAt: DateTime.parse(json['created_at'] as String),
      data: json['data'] as Map<String, dynamic>?,
    );
  }

  AppNotification toEntity() => AppNotification(
        id: id,
        title: title,
        body: body,
        type: _parseType(type),
        isRead: isRead,
        createdAt: createdAt,
        data: data,
      );

  static NotificationType _parseType(String t) {
    switch (t) {
      case 'float_approved':
        return NotificationType.floatApproved;
      case 'float_rejected':
        return NotificationType.floatRejected;
      case 'commission_credited':
        return NotificationType.commissionCredited;
      case 'security_alert':
        return NotificationType.securityAlert;
      case 'transaction_completed':
        return NotificationType.transactionCompleted;
      default:
        return NotificationType.general;
    }
  }
}
