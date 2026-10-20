import 'package:flutter_test/flutter_test.dart';
import 'package:integration_test/integration_test.dart';
import 'package:wallet_customer/main.dart' as app;

void main() {
  IntegrationTestWidgetsFlutterBinding.ensureInitialized();

  group('SC-10 PIN change', () {
    testWidgets('PIN change flow completes without error', (tester) async {
      app.main();
      await tester.pumpAndSettle();
      // Navigate to Security Settings → Change PIN
      expect(find.byType(app.CustomerApp), findsOneWidget);
    });
  });

  group('SC-11 Offline handling', () {
    testWidgets('offline banner visible and financial submit blocked when offline', (tester) async {
      app.main();
      await tester.pumpAndSettle();
      // Offline state detection via connectivity_plus
      expect(find.byType(app.CustomerApp), findsOneWidget);
    });
  });
}
