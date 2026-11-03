import 'package:dio/dio.dart';

import 'package:wallet_agent/modules/notifications/domain/repositories/notification_repository.dart';

class NotificationRepositoryImpl implements NotificationRepository {
  final Dio _dio;
  NotificationRepositoryImpl({required Dio dio}) : _dio = dio;

  @override
  Future<int> getUnreadCount() async {
    final response = await _dio.get<Map<String, dynamic>>(
      '/v1/notifications/inbox/unread-count',
    );
    final count = response.data?['data']?['unread_count'];
    if (count is! num || count < 0) {
      throw const FormatException('Invalid unread notification count.');
    }
    return count.toInt();
  }

  @override
  Future<void> markRead(String notificationId) async {
    await _dio.patch<void>(
      '/v1/notifications/inbox/read',
      data: <String, Object>{
        'ids': <String>[notificationId]
      },
    );
  }

  @override
  Future<void> markAllRead() async {
    await _dio.patch<void>(
      '/v1/notifications/inbox/read',
      data: const <String, Object>{'mark_all': true},
    );
  }
}
