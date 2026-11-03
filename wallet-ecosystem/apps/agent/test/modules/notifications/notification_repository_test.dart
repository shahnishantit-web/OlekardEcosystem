import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:wallet_agent/modules/notifications/data/repositories/notification_repository_impl.dart';

class _MockDio extends Mock implements Dio {}

void main() {
  late _MockDio dio;
  late NotificationRepositoryImpl repository;

  setUp(() {
    dio = _MockDio();
    repository = NotificationRepositoryImpl(dio: dio);
    registerFallbackValue(RequestOptions(path: ''));
  });

  test('reads unread count from the authenticated Notifications contract',
      () async {
    when(() => dio.get<Map<String, dynamic>>(
        '/v1/notifications/inbox/unread-count')).thenAnswer(
      (_) async => Response(
        data: <String, dynamic>{
          'data': <String, dynamic>{'unread_count': 4},
        },
        statusCode: 200,
        requestOptions:
            RequestOptions(path: '/v1/notifications/inbox/unread-count'),
      ),
    );

    expect(await repository.getUnreadCount(), 4);
  });

  test(
      'rejects malformed unread-count responses instead of hiding contract errors',
      () async {
    when(() => dio.get<Map<String, dynamic>>(
        '/v1/notifications/inbox/unread-count')).thenAnswer(
      (_) async => Response(
        data: <String, dynamic>{'count': 4},
        statusCode: 200,
        requestOptions:
            RequestOptions(path: '/v1/notifications/inbox/unread-count'),
      ),
    );

    expect(repository.getUnreadCount(), throwsFormatException);
  });

  test('marks a notification read using recipient-scoped inbox request',
      () async {
    when(() => dio.patch<void>(
          '/v1/notifications/inbox/read',
          data: any(named: 'data'),
        )).thenAnswer((_) async => Response<void>(
          statusCode: 200,
          requestOptions: RequestOptions(path: '/v1/notifications/inbox/read'),
        ));

    await repository.markRead('notification-id');

    verify(() => dio.patch<void>(
          '/v1/notifications/inbox/read',
          data: <String, Object>{
            'ids': <String>['notification-id']
          },
        )).called(1);
  });

  test('marks all notifications read using the shared inbox endpoint',
      () async {
    when(() => dio.patch<void>(
          '/v1/notifications/inbox/read',
          data: any(named: 'data'),
        )).thenAnswer((_) async => Response<void>(
          statusCode: 200,
          requestOptions: RequestOptions(path: '/v1/notifications/inbox/read'),
        ));

    await repository.markAllRead();

    verify(() => dio.patch<void>(
          '/v1/notifications/inbox/read',
          data: const <String, Object>{'mark_all': true},
        )).called(1);
  });
}
