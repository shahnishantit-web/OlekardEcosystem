import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'package:wallet_agent/core/api/api_client.dart';
import 'package:wallet_agent/modules/notifications/data/repositories/notification_repository_impl.dart';
import 'package:wallet_agent/modules/notifications/domain/repositories/notification_repository.dart';

final notificationRepositoryProvider = Provider<NotificationRepository>((ref) {
  return NotificationRepositoryImpl(dio: createDio(null));
});

/// Unread notification count, refreshed when the shell is mounted.
final unreadCountProvider = FutureProvider.autoDispose<int>((ref) async {
  return ref.read(notificationRepositoryProvider).getUnreadCount();
});
