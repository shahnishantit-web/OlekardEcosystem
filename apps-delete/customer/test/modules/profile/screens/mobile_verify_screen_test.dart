import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:customer/modules/profile/screens/mobile_verify_screen.dart';
import 'package:customer/modules/profile/state/profile_state.dart';
import '../helpers/profile_test_helpers.dart';

void main() {
  group('MobileVerifyScreen — widget tests (T132)', () {
    testWidgets('renders OTP input and verify button', (tester) async {
      await tester.pumpWidget(wrapProfile(const MobileVerifyScreen()));
      await tester.pump();

      expect(find.byType(TextFormField), findsOneWidget);
      expect(find.text('Verify'), findsOneWidget);
    });

    testWidgets('shows masked destination from challenge', (tester) async {
      final notifier = StatefulNotifier(
        ProfileLoaded(
          profile: fakeProfile,
          contactChallenge: fakeMobileChallenge(),
        ),
      );
      await tester.pumpWidget(wrapProfile(const MobileVerifyScreen(),
          notifier: notifier));
      await tester.pump();

      expect(find.textContaining('+267*****321'), findsOneWidget);
    });

    testWidgets('shows loading widget while isSubmitting', (tester) async {
      final notifier = StatefulNotifier(
        const ProfileLoaded(profile: fakeProfile, isSubmitting: true),
      );
      await tester.pumpWidget(wrapProfile(const MobileVerifyScreen(),
          notifier: notifier));
      await tester.pump();

      expect(find.byType(CircularProgressIndicator), findsWidgets);
    });

    testWidgets('shows OTP_INVALID error', (tester) async {
      final challenge = fakeMobileChallenge().copyWith(
        errorCode: 'OTP_INVALID',
        errorMessage: 'raw',
      );
      final notifier = StatefulNotifier(
        ProfileLoaded(profile: fakeProfile, contactChallenge: challenge),
      );
      await tester.pumpWidget(wrapProfile(const MobileVerifyScreen(),
          notifier: notifier));
      await tester.pump();

      expect(find.text('Incorrect code. Please try again.'), findsOneWidget);
    });

    testWidgets('shows expiry guidance for OTP_EXPIRED', (tester) async {
      final challenge = fakeMobileChallenge().copyWith(
        errorCode: 'OTP_EXPIRED',
        errorMessage: 'raw',
      );
      final notifier = StatefulNotifier(
        ProfileLoaded(profile: fakeProfile, contactChallenge: challenge),
      );
      await tester.pumpWidget(wrapProfile(const MobileVerifyScreen(),
          notifier: notifier));
      await tester.pump();

      expect(find.textContaining('expired'), findsWidgets);
    });

    testWidgets('back button label is "Use a different number"',
        (tester) async {
      await tester.pumpWidget(wrapProfile(const MobileVerifyScreen()));
      await tester.pump();

      expect(find.text('Use a different number'), findsOneWidget);
    });

    testWidgets('accessibility: OTP input has semantic label', (tester) async {
      await tester.pumpWidget(wrapProfile(const MobileVerifyScreen()));
      await tester.pump();

      final semantics =
          tester.getSemantics(find.byType(TextFormField).first);
      expect(semantics.label, isNotEmpty);
    });
  });
}
