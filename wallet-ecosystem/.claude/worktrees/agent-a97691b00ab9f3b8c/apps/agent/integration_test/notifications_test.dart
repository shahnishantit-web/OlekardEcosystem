// Integration test stub — SC-09 Push notifications
import 'package:flutter_test/flutter_test.dart';
import 'package:integration_test/integration_test.dart';

import 'package:wallet_agent/main.dart' as app;

void main() {
  IntegrationTestWidgetsFlutterBinding.ensureInitialized();

  testWidgets('SC-09: Push notification deep-links to correct screen', (tester) async {
    app.main();
    await tester.pumpAndSettle();
    // TODO(docker): send FCM test notification with type=float_approved
    // Assert FloatScreen is displayed
  });
}
