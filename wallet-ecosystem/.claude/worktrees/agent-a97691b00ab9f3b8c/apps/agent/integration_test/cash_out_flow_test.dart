// Integration test stub — SC-03 / SC-04 Cash-Out flow
import 'package:flutter_test/flutter_test.dart';
import 'package:integration_test/integration_test.dart';

import 'package:wallet_agent/main.dart' as app;

void main() {
  IntegrationTestWidgetsFlutterBinding.ensureInitialized();

  testWidgets('SC-03: Agent completes a cash-out', (tester) async {
    app.main();
    await tester.pumpAndSettle();
    // TODO(docker): seed agent + customer
    // 1. Log in, tap Cash Out shortcut
    // 2. Search customer, enter amount
    // 3. Enter PIN on confirmation screen (verify online check fires)
    // 4. Assert success screen + float balance updated
  });

  testWidgets('SC-04: Step-up required for large cash-out', (tester) async {
    app.main();
    await tester.pumpAndSettle();
    // TODO(docker): configure step-up threshold < transaction amount
    // Assert StepUpPinScreen is presented
  });
}
