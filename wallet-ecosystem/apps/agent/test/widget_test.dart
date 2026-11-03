import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:wallet_agent/core/notifications/push_notification_service.dart';
import 'package:wallet_agent/main.dart';

class _NoopPushNotificationService implements PushNotificationService {
  @override
  Future<void> initialize() async {}

  @override
  Future<String?> getFcmToken() async => null;
}

void main() {
  testWidgets('AgentApp smoke test', (WidgetTester tester) async {
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          pushNotificationServiceProvider.overrideWith(
            (_) => _NoopPushNotificationService(),
          ),
        ],
        child: const AgentApp(),
      ),
    );
  });
}
