import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:integration_test/integration_test.dart';
import 'package:wallet_customer/core/router/app_router.dart';
import 'package:wallet_customer/core/security/biometric_service.dart';
import 'package:wallet_customer/core/security/secure_storage_service.dart';
import 'package:wallet_customer/modules/auth/presentation/auth_providers.dart';

class _NoBiometrics extends BiometricService {
  @override
  Future<bool> isAvailable() async => false;
}

// Local-development smoke test. Requires a running host API on port 8000.
// Android uses 10.0.2.2 by default; API_BASE_URL can override that endpoint.
// flutter test integration_test/login_connection_test.dart -d emulator-5554
//   --dart-define=RUN_LOCAL_LOGIN_SMOKE=true
void main() {
  IntegrationTestWidgetsFlutterBinding.ensureInitialized();

  testWidgets('customer login can obtain a PIN challenge from Android',
      (tester) async {
    final container = ProviderContainer();
    addTearDown(container.dispose);
    final result = await tester.runAsync(() => container
        .read(authRepositoryProvider)
        .initiateLogin(phone: '+26771000001', method: 'pin'));
    expect(result, isNotNull);
    expect(result!.challengeToken, isNotEmpty);
    expect(result.method, 'pin');
  }, skip: !const bool.fromEnvironment('RUN_LOCAL_LOGIN_SMOKE'));

  testWidgets('Log In opens the PIN sheet after the real API challenge',
      (tester) async {
    final container = ProviderContainer(overrides: [
      biometricServiceProvider.overrideWithValue(_NoBiometrics()),
    ]);
    addTearDown(container.dispose);
    await tester.pumpWidget(UncontrolledProviderScope(
      container: container,
      child: Consumer(builder: (context, ref, _) {
        return MaterialApp.router(routerConfig: ref.watch(routerProvider));
      }),
    ));
    container.read(routerProvider).go('/login');
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextFormField), '+26771000001');
    await tester.tap(find.text('Log In'));
    await tester.runAsync(() async {
      await Future<void>(() async {
        while (container.read(authProvider).status ==
            AuthStateEnum.authenticating) {
          await Future<void>.delayed(const Duration(milliseconds: 100));
        }
      }).timeout(const Duration(seconds: 40));
    });
    await tester.pump();
    await tester.pump(const Duration(seconds: 1));
    expect(container.read(authProvider).challengeToken, isNotEmpty);
    expect(container.read(authProvider).isAuthenticated, isFalse);
    expect(find.text('Enter your PIN'), findsOneWidget);
    expect(tester.takeException(), isNull);
    if (const bool.fromEnvironment('RUN_LOCAL_PIN_SMOKE')) {
      await tester.enterText(find.byType(TextFormField).last, '123456');
      await tester.pump(const Duration(milliseconds: 500));
      await tester.runAsync(() async {
        await Future<void>(() async {
          while (!container.read(authProvider).isAuthenticated &&
              container.read(authProvider).error == null) {
            await Future<void>.delayed(const Duration(milliseconds: 100));
          }
        }).timeout(const Duration(seconds: 40));
      });
      await tester.pump();
      await tester.pump(const Duration(seconds: 1));
      expect(container.read(authProvider).error, isNull);
      expect(container.read(authProvider).isAuthenticated, isTrue);
      expect(container.read(authProvider).customer?.phone, '+26771000001');
      expect(
          container
              .read(routerProvider)
              .routeInformationProvider
              .value
              .uri
              .path,
          '/home');
      final token = await tester
          .runAsync(() => const SecureStorageService().readAccessToken());
      expect(token, isNotEmpty);
      expect(tester.takeException(), isNull);
    }
    await tester.pumpWidget(const SizedBox.shrink());
  }, skip: !const bool.fromEnvironment('RUN_LOCAL_LOGIN_SMOKE'));
}
