import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../domain/entities/notification.dart';
import '../domain/repositories/notification_repository.dart';
import '../data/repositories/notification_repository_impl.dart';
import '../../../../core/api/paginated_result.dart';
import '../../../core/di/providers.dart';

final notificationRepositoryProvider = Provider<NotificationRepository>((ref) {
  return NotificationRepositoryImpl(apiClient: ref.watch(apiClientProvider));
});

/// Alias kept for compatibility with home/notifications_screen.dart
final notificationListProvider =
    FutureProvider.autoDispose.family<PaginatedResult<AppNotification>, int>((ref, page) async {
  return ref.watch(notificationRepositoryProvider).getNotifications(page);
});

/// Original name — kept for backward compatibility
final notificationsProvider = notificationListProvider;

final unreadCountProvider = FutureProvider.autoDispose<int>((ref) async {
  return ref.watch(notificationRepositoryProvider).getUnreadCount();
});

/// Provides imperative actions (mark read / mark all read).
class NotificationActions {
  final NotificationRepository _repo;

  const NotificationActions(this._repo);

  Future<void> markRead(String id) => _repo.markAsRead(id);
  Future<void> markAllRead() => _repo.markAllAsRead();
}

final notificationActionsProvider = Provider<NotificationActions>((ref) {
  return NotificationActions(ref.watch(notificationRepositoryProvider));
});
