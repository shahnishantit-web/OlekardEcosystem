// Integration test stub — SC-07 Auth / Session management
import 'package:flutter_test/flutter_test.dart';
import 'package:integration_test/integration_test.dart';

import 'package:wallet_agent/main.dart' as app;

void main() {
  IntegrationTestWidgetsFlutterBinding.ensureInitialized();

  testWidgets('SC-07: 5-minute background timeout forces re-auth', (tester) async {
    app.main();
    await tester.pumpAndSettle();
    // TODO(docker):
    // 1. Log in successfully
    // 2. Simulate app going background for >5 minutes
    // 3. Bring to foreground — assert LoginScreen is shown
  });

  testWidgets('SC-08: PIN lockout after 5 failed attempts', (tester) async {
    app.main();
    await tester.pumpAndSettle();
    // TODO(docker): attempt login with wrong PIN 5 times
    // Assert account locked (423 error with attempts_remaining=0)
  });
}
