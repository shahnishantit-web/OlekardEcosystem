import '../../../../core/api/paginated_result.dart';
import '../entities/notification.dart';

abstract class NotificationRepository {
  Future<PaginatedResult<AppNotification>> getNotifications(int page);
  Future<void> markAsRead(String notificationId);
  Future<void> markAllAsRead();
  Future<int> getUnreadCount();
}
