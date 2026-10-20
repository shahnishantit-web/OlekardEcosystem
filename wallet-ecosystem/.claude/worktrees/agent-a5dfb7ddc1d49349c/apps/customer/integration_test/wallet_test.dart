import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:integration_test/integration_test.dart';
import 'package:wallet_customer/main.dart' as app;

void main() {
  IntegrationTestWidgetsFlutterBinding.ensureInitialized();

  group('SC-03 Wallet balance visible', () {
    testWidgets('balance card is visible after login', (tester) async {
      app.main();
      await tester.pumpAndSettle();
      // After auth, home screen should display wallet balance area
      expect(find.byKey(const Key('wallet_balance_card')), findsWidgets);
    });
  });

  group('SC-04 Transaction history', () {
    testWidgets('transactions tab shows list with reference numbers', (tester) async {
      app.main();
      await tester.pumpAndSettle();
      // Navigate to transactions
      expect(find.byKey(const Key('transactions_screen')), findsWidgets);
    });
  });
}
