import 'package:flutter_test/flutter_test.dart';
import 'package:wallet_agent/modules/notifications/data/models/notification_dto.dart';
import 'package:wallet_agent/modules/notifications/domain/entities/app_notification.dart';

void main() {
  test('maps shared Notifications inbox fields to the Agent model', () {
    final notification = AppNotificationDto.fromJson(<String, dynamic>{
      'id': 'notification-1',
      'notification_type': 'security_alert',
      'category': 'security',
      'subject': 'New sign-in',
      'body': 'A new sign-in was detected.',
      'read': false,
      'created_at': '2026-11-02T08:30:00Z',
    }).toEntity();

    expect(notification.id, 'notification-1');
    expect(notification.title, 'New sign-in');
    expect(notification.type, NotificationType.securityAlert);
    expect(notification.isRead, isFalse);
    expect(notification.createdAt, DateTime.parse('2026-11-02T08:30:00Z'));
  });
}
