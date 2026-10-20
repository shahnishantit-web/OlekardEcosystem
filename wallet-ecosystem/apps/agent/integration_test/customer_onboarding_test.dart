// Integration test stub — SC-06 Customer Onboarding
import 'package:flutter_test/flutter_test.dart';
import 'package:integration_test/integration_test.dart';

import 'package:wallet_agent/main.dart' as app;

void main() {
  IntegrationTestWidgetsFlutterBinding.ensureInitialized();

  testWidgets('SC-06: Agent registers a new customer', (tester) async {
    app.main();
    await tester.pumpAndSettle();
    // TODO(docker):
    // 1. Log in, tap Register Customer shortcut
    // 2. Fill all fields, submit
    // 3. Assert success screen shows wallet number
  });
}
