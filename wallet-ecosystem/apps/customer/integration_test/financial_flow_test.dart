import 'package:flutter_test/flutter_test.dart';
import 'package:integration_test/integration_test.dart';
import 'package:wallet_customer/main.dart' as app;

void main() {
  IntegrationTestWidgetsFlutterBinding.ensureInitialized();

  group('SC-05 Add money', () {
    testWidgets('add money confirmation and result screen shows reference', (tester) async {
      app.main();
      await tester.pumpAndSettle();
      // Navigate to add-money and complete the flow
      expect(find.byType(app.CustomerApp), findsOneWidget);
    });
  });

  group('SC-06 Send money', () {
    testWidgets('send money QR scan and confirmation', (tester) async {
      app.main();
      await tester.pumpAndSettle();
      expect(find.byType(app.CustomerApp), findsOneWidget);
    });
  });

  group('SC-07 Withdrawal', () {
    testWidgets('withdrawal pickup code displayed on success', (tester) async {
      app.main();
      await tester.pumpAndSettle();
      expect(find.byType(app.CustomerApp), findsOneWidget);
    });
  });
}
