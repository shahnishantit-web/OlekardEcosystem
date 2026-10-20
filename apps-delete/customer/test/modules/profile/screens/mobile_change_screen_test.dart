import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:customer/modules/profile/screens/mobile_change_screen.dart';
import 'package:customer/modules/profile/state/profile_state.dart';
import '../helpers/profile_test_helpers.dart';

void main() {
  group('MobileChangeScreen — widget tests (T131)', () {
    testWidgets('renders mobile input and send button', (tester) async {
      await tester.pumpWidget(wrapProfile(const MobileChangeScreen()));
      await tester.pump();

      expect(find.byType(TextFormField), findsOneWidget);
      expect(find.text('Send verification code'), findsOneWidget);
    });

    testWidgets('shows loading widget while isSubmitting', (tester) async {
      final notifier = StatefulNotifier(
        const ProfileLoaded(profile: fakeProfile, isSubmitting: true),
      );
      await tester.pumpWidget(wrapProfile(const MobileChangeScreen(),
          notifier: notifier));
      await tester.pump();

      expect(find.byType(CircularProgressIndicator), findsWidgets);
    });

    testWidgets('validation rejects empty mobile', (tester) async {
      await tester.pumpWidget(wrapProfile(const MobileChangeScreen()));
      await tester.pump();

      await tester.tap(find.text('Send verification code'));
      await tester.pump();

      expect(find.text('Mobile number is required'), findsOneWidget);
    });

    testWidgets('validation rejects non-E164 number', (tester) async {
      await tester.pumpWidget(wrapProfile(const MobileChangeScreen()));
      await tester.pump();

      await tester.enterText(find.byType(TextFormField), '071234567');
      await tester.tap(find.text('Send verification code'));
      await tester.pump();

      expect(find.textContaining('E.164 format'), findsOneWidget);
    });

    testWidgets('shows friendly error for CONTACT_CHANGE_IN_PROGRESS',
        (tester) async {
      final notifier = StatefulNotifier(
        const ProfileLoaded(
          profile: fakeProfile,
          errorCode: 'CONTACT_CHANGE_IN_PROGRESS',
          errorMessage: 'raw',
        ),
      );
      await tester.pumpWidget(wrapProfile(const MobileChangeScreen(),
          notifier: notifier));
      await tester.pump();

      expect(
        find.textContaining('already in progress', findRichText: true),
        findsOneWidget,
      );
    });

    testWidgets('accessibility: mobile field has a semantic label',
        (tester) async {
      await tester.pumpWidget(wrapProfile(const MobileChangeScreen()));
      await tester.pump();

      final semantics =
          tester.getSemantics(find.byType(TextFormField).first);
      expect(semantics.label, isNotEmpty);
    });
  });
}
