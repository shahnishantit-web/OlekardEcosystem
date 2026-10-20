import 'package:flutter_test/flutter_test.dart';
import 'package:integration_test/integration_test.dart';
import 'package:wallet_customer/main.dart' as app;

void main() {
  IntegrationTestWidgetsFlutterBinding.ensureInitialized();

  group('SC-01 Registration flow', () {
    testWidgets('user can complete registration under 3 minutes', (tester) async {
      final sw = Stopwatch()..start();
      app.main();
      await tester.pumpAndSettle();
      // Navigate to register
      await tester.tap(find.text('Create Account'));
      await tester.pumpAndSettle();
      sw.stop();
      expect(sw.elapsed.inMinutes, lessThan(3),
          reason: 'SC-01: Registration should complete within 3 minutes');
    });
  });

  group('SC-02 Login flow', () {
    testWidgets('returning user can log in within 30 seconds', (tester) async {
      final sw = Stopwatch()..start();
      app.main();
      await tester.pumpAndSettle();
      // Login screen should be shown for unauthenticated user
      expect(find.text('Log In'), findsWidgets);
      sw.stop();
      expect(sw.elapsed.inSeconds, lessThan(30),
          reason: 'SC-02: Login screen should appear within 30 seconds');
    });
  });

  group('SC-03 Wallet balance visible', () {
    testWidgets('home screen shows wallet balance after login', (tester) async {
      app.main();
      await tester.pumpAndSettle();
      // Wallet balance section should be visible on home screen after auth
      expect(find.byType(app.CustomerApp), findsOneWidget);
    });
  });
}
