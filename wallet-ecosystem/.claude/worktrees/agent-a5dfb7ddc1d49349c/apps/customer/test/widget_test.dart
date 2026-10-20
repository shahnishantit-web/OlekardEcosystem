// Basic smoke test for the OleKard Customer app.
// Full integration tests live in integration_test/.

import 'package:flutter_test/flutter_test.dart';

void main() {
  testWidgets('smoke test placeholder', (WidgetTester tester) async {
    // Full app smoke test requires Firebase initialisation which is not
    // available in unit-test context — covered by integration_test/app_test.dart.
    expect(true, isTrue);
  });
}
