import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:customer/modules/profile/screens/email_verify_screen.dart';
import 'package:customer/modules/profile/state/profile_state.dart';
import '../helpers/profile_test_helpers.dart';

void main() {
  group('EmailVerifyScreen — widget tests (T130)', () {
    testWidgets('renders OTP input and verify button', (tester) async {
      await tester.pumpWidget(wrapProfile(const EmailVerifyScreen()));
      await tester.pump();

      expect(find.byType(TextFormField), findsOneWidget);
      expect(find.text('Verify'), findsOneWidget);
    });

    testWidgets('shows masked destination when challenge is set',
        (tester) async {
      final notifier = StatefulNotifier(
        ProfileLoaded(
          profile: fakeProfile,
          contactChallenge: fakeEmailChallenge(),
        ),
      );
      await tester.pumpWidget(wrapProfile(const EmailVerifyScreen(),
          notifier: notifier));
      await tester.pump();

      expect(find.textContaining('n**@example.com'), findsOneWidget);
    });

    testWidgets('shows loading widget while isSubmitting', (tester) async {
      final notifier = StatefulNotifier(
        const ProfileLoaded(profile: fakeProfile, isSubmitting: true),
      );
      await tester.pumpWidget(wrapProfile(const EmailVerifyScreen(),
          notifier: notifier));
      await tester.pump();

      expect(find.byType(CircularProgressIndicator), findsWidgets);
    });

    testWidgets('shows OTP_INVALID error in form field', (tester) async {
      final challenge = fakeEmailChallenge().copyWith(
        errorCode: 'OTP_INVALID',
        errorMessage: 'raw',
      );
      final notifier = StatefulNotifier(
        ProfileLoaded(profile: fakeProfile, contactChallenge: challenge),
      );
      await tester.pumpWidget(wrapProfile(const EmailVerifyScreen(),
          notifier: notifier));
      await tester.pump();

      expect(find.text('Incorrect code. Please try again.'), findsOneWidget);
    });

    testWidgets('shows expiry guidance for OTP_EXPIRED', (tester) async {
      final challenge = fakeEmailChallenge().copyWith(
        errorCode: 'OTP_EXPIRED',
        errorMessage: 'raw',
      );
      final notifier = StatefulNotifier(
        ProfileLoaded(profile: fakeProfile, contactChallenge: challenge),
      );
      await tester.pumpWidget(wrapProfile(const EmailVerifyScreen(),
          notifier: notifier));
      await tester.pump();

      expect(find.textContaining('expired'), findsWidgets);
    });

    testWidgets('back button label is "Use a different email"', (tester) async {
      await tester.pumpWidget(wrapProfile(const EmailVerifyScreen()));
      await tester.pump();

      expect(find.text('Use a different email'), findsOneWidget);
    });

    testWidgets('accessibility: OTP input has semantic label', (tester) async {
      await tester.pumpWidget(wrapProfile(const EmailVerifyScreen()));
      await tester.pump();

      final semantics =
          tester.getSemantics(find.byType(TextFormField).first);
      expect(semantics.label, isNotEmpty);
    });
  });
}
