// Integration test stub — SC-01 / SC-02 Cash-In flow
// Run in Docker stack: flutter test integration_test/cash_in_flow_test.dart
import 'package:flutter_test/flutter_test.dart';
import 'package:integration_test/integration_test.dart';

import 'package:wallet_agent/main.dart' as app;

void main() {
  IntegrationTestWidgetsFlutterBinding.ensureInitialized();

  testWidgets('SC-01: Agent logs in and completes a cash-in', (tester) async {
    app.main();
    await tester.pumpAndSettle();
    // TODO(docker): seed agent +26771000001 / PIN 1234, customer +26771000002
    // 1. Enter phone and PIN on LoginScreen
    // 2. Tap Cash In shortcut on DashboardScreen
    // 3. Search customer by phone
    // 4. Confirm customer, enter amount
    // 5. Enter PIN on confirmation screen
    // 6. Assert success screen shows reference number
  });

  testWidgets('SC-02: Cash-in blocked when agent is offline', (tester) async {
    app.main();
    await tester.pumpAndSettle();
    // TODO(docker): simulate no network via test harness
    // Assert Cash In tile is disabled (opacity 0.4, no tap action)
  });
}
