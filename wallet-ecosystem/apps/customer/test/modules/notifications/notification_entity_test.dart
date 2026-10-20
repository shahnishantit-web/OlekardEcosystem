import 'package:flutter_test/flutter_test.dart';
import 'package:wallet_customer/modules/notifications/domain/entities/notification.dart';

void main() {
  group('AppNotification entity', () {
    final n = AppNotification(
      id: 'n-001',
      title: 'Transfer Received',
      body: 'You received KES 500.',
      type: NotificationType.transaction,
      isRead: false,
      deepLink: '/transactions',
      createdAt: DateTime(2024, 1, 1),
    );

    test('copyWith marks notification as read', () {
      final read = n.copyWith(isRead: true);
      expect(read.isRead, isTrue);
      expect(n.isRead, isFalse);
    });

    test('type is transaction', () {
      expect(n.type, NotificationType.transaction);
    });

    test('deepLink is preserved', () {
      expect(n.deepLink, '/transactions');
    });
  });
}
