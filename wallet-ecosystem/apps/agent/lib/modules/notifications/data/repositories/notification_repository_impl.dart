import 'package:dio/dio.dart';

import 'package:wallet_agent/modules/notifications/domain/repositories/notification_repository.dart';

class NotificationRepositoryImpl implements NotificationRepository {
  final Dio _dio;
  NotificationRepositoryImpl({required Dio dio}) : _dio = dio;

  @override
  Future<int> getUnreadCount() async {
    final response = await _dio.get<Map<String, dynamic>>(
      '/v1/agent/notifications/unread-count',
    );
    return (response.data?['count'] as num?)?.toInt() ?? 0;
  }

  @override
  Future<void> markRead(String notificationId) async {
    await _dio.patch<void>('/v1/agent/notifications/$notificationId/read');
  }

  @override
  Future<void> markAllRead() async {
    await _dio.post<void>('/v1/agent/notifications/mark-all-read');
  }
}
