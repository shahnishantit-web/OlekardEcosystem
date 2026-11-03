import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:integration_test/integration_test.dart';
import 'package:wallet_agent/main.dart' as app;

const _phone = String.fromEnvironment('P26_E2E_OPERATOR_PHONE');
const _pin = String.fromEnvironment('P26_E2E_OPERATOR_PIN');
const _eventId = String.fromEnvironment('P26_E2E_NATIVE_EVENT_ID');
const _gateId = String.fromEnvironment('P26_E2E_NATIVE_GATE_ID');
const _wrongGateId = String.fromEnvironment('P26_E2E_NATIVE_WRONG_GATE_ID');

// This credential belongs only to the explicitly seeded isolated fixture.
const _fixtureCredential = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFG';

Future<void> _waitFor(WidgetTester tester, Finder finder) async {
  for (var attempt = 0; attempt < 100; attempt++) {
    await tester.pump(const Duration(milliseconds: 250));
    if (finder.evaluate().isNotEmpty) return;
  }
  fail('Timed out waiting for an expected Agent UI state.');
}

Finder _field(String label) => find.byWidgetPredicate(
    (widget) => widget is TextField && widget.decoration?.labelText == label);

void main() {
  IntegrationTestWidgetsFlutterBinding.ensureInitialized();

  testWidgets(
      'P26 native assigned operator admits online and denies wrong gate',
      (tester) async {
    expect(_phone, isNotEmpty);
    expect(_pin, matches(RegExp(r'^\d{6}$')));
    expect(_eventId, isNotEmpty);
    expect(_gateId, isNotEmpty);
    expect(_wrongGateId, isNotEmpty);
    // Agent startup awaits Flutter bindings and initializes Firebase before it
    // mounts the app. The integration test must wait for that startup so its
    // first login-field lookup observes the real screen.
    await app.main();

    final phoneField = _field('Phone Number');
    await _waitFor(tester, phoneField);
    await tester.enterText(phoneField, _phone);
    final pinField = find.byWidgetPredicate((widget) =>
        widget is TextField && widget.keyboardType == TextInputType.number);
    await tester.enterText(pinField, _pin);

    final gateAction = find.byTooltip('Open Event Gate scanner');
    await _waitFor(tester, gateAction);
    await tester.tap(gateAction);
    await tester.pumpAndSettle();
    await _waitFor(tester, find.text('Event Gate Admission'));
    await tester.enterText(_field('Event ID'), _eventId);
    await tester.enterText(_field('Gate ID'), _wrongGateId);
    await tester.enterText(
        _field('Credential (manual fallback)'), _fixtureCredential);
    await tester.tap(find.text('Validate credential'));
    await _waitFor(tester, find.text('Admission denied'));

    await tester.enterText(_field('Gate ID'), _gateId);
    await tester.enterText(
        _field('Credential (manual fallback)'), _fixtureCredential);
    await tester.tap(find.text('Validate credential'));
    await _waitFor(tester, find.text('Admit attendee'));

    await tester.enterText(
        _field('Credential (manual fallback)'), _fixtureCredential);
    await tester.tap(find.text('Validate credential'));
    await _waitFor(tester, find.text('Already admitted'));
    expect(tester.takeException(), isNull);
  });
}
