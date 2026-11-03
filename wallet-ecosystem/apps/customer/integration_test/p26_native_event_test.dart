import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:integration_test/integration_test.dart';
import 'package:wallet_customer/core/router/app_router.dart';
import 'package:wallet_customer/core/security/biometric_service.dart';
import 'package:wallet_customer/modules/auth/presentation/auth_providers.dart';

const _phone = String.fromEnvironment('P26_E2E_CUSTOMER_PHONE');
const _pin = String.fromEnvironment('P26_E2E_CUSTOMER_PIN');
const _eventId = String.fromEnvironment('P26_E2E_NATIVE_EVENT_ID');
const _categoryId = String.fromEnvironment('P26_E2E_NATIVE_CATEGORY_ID');

class _NoBiometrics extends BiometricService {
  @override
  Future<bool> isAvailable() async => false;
}

Future<void> _waitFor(WidgetTester tester, Finder finder) async {
  for (var attempt = 0; attempt < 100; attempt++) {
    await tester.pump(const Duration(milliseconds: 250));
    if (finder.evaluate().isNotEmpty) return;
  }
  fail('Timed out waiting for an expected Customer UI state.');
}

void main() {
  IntegrationTestWidgetsFlutterBinding.ensureInitialized();

  testWidgets(
      'P26 native customer logs in, registers and opens purchaser tickets',
      (tester) async {
    expect(_phone, isNotEmpty);
    expect(_pin, matches(RegExp(r'^\d{6}$')));
    expect(_eventId, isNotEmpty);
    expect(_categoryId, isNotEmpty);
    final container = ProviderContainer(overrides: [
      biometricServiceProvider.overrideWithValue(_NoBiometrics()),
    ]);
    addTearDown(container.dispose);
    await tester.pumpWidget(UncontrolledProviderScope(
      container: container,
      child: Consumer(
          builder: (context, ref, _) => MaterialApp.router(
                routerConfig: ref.watch(routerProvider),
              )),
    ));
    container.read(routerProvider).go('/login');
    await tester.pumpAndSettle();

    final loginButton = find.text('Log In');
    await _waitFor(tester, loginButton);
    await tester.enterText(find.byType(TextFormField).first, _phone);
    await tester.tap(loginButton);
    await tester.runAsync(() async {
      while (
          container.read(authProvider).status == AuthStateEnum.authenticating) {
        await Future<void>.delayed(const Duration(milliseconds: 100));
      }
    });
    await tester.pump();
    final challengeState = container.read(authProvider);
    final safeLoginError = (challengeState.error ?? 'none')
        .replaceAll(RegExp(r'\+?[0-9]{8,15}'), '[phone]')
        .replaceAll(RegExp(r'\b[A-Za-z0-9_-]{40,}\b'), '[opaque-secret]');
    expect(challengeState.challengeToken, isNotEmpty,
        reason: 'Customer login challenge unavailable; '
            'status=${challengeState.status}; error=$safeLoginError');
    await _waitFor(tester, find.text('Enter your PIN'));
    final pinField = find.byWidgetPredicate((widget) =>
        widget is TextField && widget.keyboardType == TextInputType.number);
    await tester.enterText(pinField, _pin);
    await tester.runAsync(() async {
      for (var attempt = 0;
          attempt < 400 &&
              !container.read(authProvider).isAuthenticated &&
              container.read(authProvider).error == null;
          attempt++) {
        await Future<void>.delayed(const Duration(milliseconds: 100));
      }
    });
    await tester.pump();
    expect(container.read(authProvider).error, isNull);
    expect(container.read(authProvider).isAuthenticated, isTrue);
    expect(container.read(authProvider).customer?.phone, _phone);

    final servicesTab = find.text('Services');
    await _waitFor(tester, servicesTab);
    await tester.tap(servicesTab);
    await tester.pumpAndSettle();
    final servicesMenu = find.byTooltip('Services menu');
    await _waitFor(tester, servicesMenu);
    await tester.tap(servicesMenu);
    final registerAction = find.text('Free Event Registration');
    await _waitFor(tester, registerAction);
    await tester.tap(registerAction);
    await tester.pumpAndSettle();
    final fields = find.byType(TextField);
    await tester.enterText(fields.at(0), _eventId);
    await tester.enterText(fields.at(1), _categoryId);
    await tester.tap(find.text('Register for free'));

    await _waitFor(tester, find.text('My Event Tickets'));
    final ticketTitle = find.text('P26 isolated native event');
    final ticketError = find.text('Could not load your event tickets.');
    for (var attempt = 0;
        attempt < 100 &&
            ticketTitle.evaluate().isEmpty &&
            ticketError.evaluate().isEmpty;
        attempt++) {
      await tester.pump(const Duration(milliseconds: 250));
    }
    expect(ticketError, findsNothing);
    expect(ticketTitle, findsOneWidget);
    expect(find.text('Native free fixture'), findsOneWidget);
    expect(find.text('Ready for entry'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });
}
