import 'package:design_system/design_system.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  testWidgets('dispatches a completed PIN once and accepts a later entry',
      (tester) async {
    final completedPins = <String>[];

    await tester.pumpWidget(MaterialApp(
      home: Scaffold(
        body: OkPinPadWidget(onCompleted: completedPins.add),
      ),
    ));

    final pinInput = find.byType(EditableText).first;
    await tester.enterText(pinInput, '123456');
    await tester.pump(const Duration(milliseconds: 350));

    expect(completedPins, ['123456']);
    expect(tester.widget<EditableText>(pinInput).controller.text, isEmpty);

    await tester.enterText(pinInput, '654321');
    await tester.pump(const Duration(milliseconds: 350));

    expect(completedPins, ['123456', '654321']);
  });

  testWidgets('partial entry, deletion and correction preserve leading zeroes',
      (tester) async {
    final completedPins = <String>[];
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(
        body: OkPinPadWidget(onCompleted: completedPins.add),
      ),
    ));
    final pinInput = find.byType(EditableText).first;

    await tester.enterText(pinInput, '00123');
    await tester.pump(const Duration(milliseconds: 350));
    expect(completedPins, isEmpty);

    await tester.enterText(pinInput, '0012');
    await tester.pump(const Duration(milliseconds: 350));
    expect(completedPins, isEmpty);

    await tester.enterText(pinInput, '001234');
    await tester.pump(const Duration(milliseconds: 350));
    expect(completedPins, ['001234']);
  });
}
