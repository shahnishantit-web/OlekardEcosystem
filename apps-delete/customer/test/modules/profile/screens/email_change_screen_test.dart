import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:customer/modules/profile/screens/email_change_screen.dart';
import 'package:customer/modules/profile/state/profile_state.dart';
import '../helpers/profile_test_helpers.dart';

void main() {
  group('EmailChangeScreen — widget tests (T129)', () {
    testWidgets('renders email input and send button', (tester) async {
      await tester.pumpWidget(wrapProfile(const EmailChangeScreen()));
      await tester.pump();

      expect(find.byType(TextFormField), findsOneWidget);
      expect(find.text('Send verification code'), findsOneWidget);
    });

    testWidgets('shows loading widget while isSubmitting', (tester) async {
      final notifier = StatefulNotifier(
        const ProfileLoaded(profile: fakeProfile, isSubmitting: true),
      );
      await tester.pumpWidget(wrapProfile(const EmailChangeScreen(),
          notifier: notifier));
      await tester.pump();

      expect(find.byType(CircularProgressIndicator), findsWidgets);
    });

    testWidgets('validation rejects empty email', (tester) async {
      await tester.pumpWidget(wrapProfile(const EmailChangeScreen()));
      await tester.pump();

      await tester.tap(find.text('Send verification code'));
      await tester.pump();

      expect(find.text('Email address is required'), findsOneWidget);
    });

    testWidgets('validation rejects invalid email format', (tester) async {
      await tester.pumpWidget(wrapProfile(const EmailChangeScreen()));
      await tester.pump();

      await tester.enterText(find.byType(TextFormField), 'not-an-email');
      await tester.tap(find.text('Send verification code'));
      await tester.pump();

      expect(find.text('Enter a valid email address'), findsOneWidget);
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
      await tester.pumpWidget(wrapProfile(const EmailChangeScreen(),
          notifier: notifier));
      await tester.pump();

      expect(
        find.textContaining('already in progress', findRichText: true),
        findsOneWidget,
      );
    });

    testWidgets('shows friendly error for RATE_LIMIT_EXCEEDED', (tester) async {
      final notifier = StatefulNotifier(
        const ProfileLoaded(
          profile: fakeProfile,
          errorCode: 'RATE_LIMIT_EXCEEDED',
          errorMessage: 'raw',
        ),
      );
      await tester.pumpWidget(wrapProfile(const EmailChangeScreen(),
          notifier: notifier));
      await tester.pump();

      expect(find.textContaining('Too many attempts'), findsOneWidget);
    });

    testWidgets('accessibility: email field has a semantic label',
        (tester) async {
      await tester.pumpWidget(wrapProfile(const EmailChangeScreen()));
      await tester.pump();

      final semantics =
          tester.getSemantics(find.byType(TextFormField).first);
      expect(semantics.label, isNotEmpty);
    });
  });
}
