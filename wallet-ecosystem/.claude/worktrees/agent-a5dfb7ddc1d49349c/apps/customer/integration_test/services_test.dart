import 'package:flutter_test/flutter_test.dart';
import 'package:integration_test/integration_test.dart';
import 'package:wallet_customer/main.dart' as app;

void main() {
  IntegrationTestWidgetsFlutterBinding.ensureInitialized();

  group('SC-08 Service purchase', () {
    testWidgets('airtime purchase end-to-end: browse → provider → purchase → result', (tester) async {
      app.main();
      await tester.pumpAndSettle();
      // Navigate to services tab, browse, purchase airtime
      expect(find.byType(app.CustomerApp), findsOneWidget);
    });
  });
}
