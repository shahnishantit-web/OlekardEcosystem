import 'package:dio/dio.dart';
import '../../../../core/api/api_client.dart';
import '../../../../core/api/paginated_result.dart';
import '../../domain/entities/notification.dart';
import '../../domain/repositories/notification_repository.dart';

class NotificationRepositoryImpl implements NotificationRepository {
  final Dio _dio;

  NotificationRepositoryImpl({required ApiClient apiClient}) : _dio = apiClient.dio;

  @override
  Future<PaginatedResult<AppNotification>> getNotifications(int page) async {
    final res = await _dio.get('/v1/customer/notifications', queryParameters: {'page': page});
    return PaginatedResult.fromJson(res.data as Map<String, dynamic>, _fromJson);
  }

  @override
  Future<void> markAsRead(String notificationId) async {
    await _dio.patch('/v1/customer/notifications/$notificationId/read');
  }

  @override
  Future<void> markAllAsRead() async {
    await _dio.post('/v1/customer/notifications/mark-all-read');
  }

  @override
  Future<int> getUnreadCount() async {
    final res = await _dio.get('/v1/customer/notifications/unread-count');
    return (res.data as Map<String, dynamic>)['count'] as int? ?? 0;
  }

  AppNotification _fromJson(Map<String, dynamic> j) => AppNotification(
        id: j['id'] as String,
        title: j['title'] as String,
        body: j['body'] as String,
        type: NotificationType.values.firstWhere(
          (e) => e.name == (j['type'] as String? ?? 'system'),
          orElse: () => NotificationType.system,
        ),
        isRead: j['is_read'] as bool? ?? false,
        deepLink: j['deep_link'] as String?,
        createdAt: DateTime.parse(j['created_at'] as String),
      );
}
