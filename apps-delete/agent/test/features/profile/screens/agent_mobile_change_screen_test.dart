import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agent/features/profile/screens/agent_mobile_change_screen.dart';
import 'package:agent/features/profile/screens/agent_mobile_verify_screen.dart';
import 'package:agent/features/profile/state/agent_profile_state.dart';
import '../helpers/agent_profile_test_helpers.dart';

void main() {
  group('AgentMobileChangeScreen — widget tests (T141)', () {
    testWidgets('renders mobile number input', (tester) async {
      await tester
          .pumpWidget(wrapAgentProfile(const AgentMobileChangeScreen()));
      await tester.pump();

      expect(find.byType(TextFormField), findsOneWidget);
      expect(find.text('Send verification code'), findsOneWidget);
    });

    testWidgets('shows loading while submitting', (tester) async {
      final notifier = StatefulNotifier(
        const AgentProfileLoaded(profile: fakeProfile, isSubmitting: true),
      );
      await tester.pumpWidget(wrapAgentProfile(
          const AgentMobileChangeScreen(),
          notifier: notifier));
      await tester.pump();

      expect(find.byType(CircularProgressIndicator), findsWidgets);
    });

    testWidgets('shows error banner on failure', (tester) async {
      final notifier = StatefulNotifier(
        const AgentProfileLoaded(
          profile: fakeProfile,
          errorMessage: 'Too many attempts. Please try again later.',
          errorCode: 'RATE_LIMIT_EXCEEDED',
        ),
      );
      await tester.pumpWidget(wrapAgentProfile(
          const AgentMobileChangeScreen(),
          notifier: notifier));
      await tester.pump();

      expect(find.text('Too many attempts. Please try again later.'),
          findsOneWidget);
    });

    testWidgets('validates E.164 format', (tester) async {
      await tester
          .pumpWidget(wrapAgentProfile(const AgentMobileChangeScreen()));
      await tester.pump();

      await tester.enterText(find.byType(TextFormField), 'invalid');
      await tester.tap(find.text('Send verification code'));
      await tester.pump();

      expect(find.textContaining('E.164'), findsOneWidget);
    });

    testWidgets('requires non-empty mobile number', (tester) async {
      await tester
          .pumpWidget(wrapAgentProfile(const AgentMobileChangeScreen()));
      await tester.pump();

      await tester.tap(find.text('Send verification code'));
      await tester.pump();

      expect(find.text('Mobile number is required'), findsOneWidget);
    });
  });

  group('AgentMobileVerifyScreen — widget tests (T141)', () {
    testWidgets('renders 6-digit OTP input', (tester) async {
      await tester
          .pumpWidget(wrapAgentProfile(const AgentMobileVerifyScreen()));
      await tester.pump();

      expect(find.byType(TextFormField), findsOneWidget);
      expect(find.text('Verify'), findsOneWidget);
    });

    testWidgets('shows masked destination when challenge is active',
        (tester) async {
      final notifier = StatefulNotifier(
        AgentProfileLoaded(
          profile: fakeProfile,
          mobileChallenge: fakeMobileChallenge(),
        ),
      );
      await tester.pumpWidget(
          wrapAgentProfile(const AgentMobileVerifyScreen(), notifier: notifier));
      await tester.pump();

      expect(find.textContaining('+267'), findsOneWidget);
    });

    testWidgets('shows loading while verifying', (tester) async {
      final notifier = StatefulNotifier(
        const AgentProfileLoaded(profile: fakeProfile, isSubmitting: true),
      );
      await tester.pumpWidget(wrapAgentProfile(
          const AgentMobileVerifyScreen(),
          notifier: notifier));
      await tester.pump();

      expect(find.byType(CircularProgressIndicator), findsWidgets);
    });

    testWidgets('shows OTP_INVALID error in text field', (tester) async {
      final challenge = fakeMobileChallenge().copyWith(
        errorCode: 'OTP_INVALID',
        errorMessage: 'Incorrect code. Please try again.',
      );
      final notifier = StatefulNotifier(
        AgentProfileLoaded(
          profile: fakeProfile,
          mobileChallenge: challenge,
        ),
      );
      await tester.pumpWidget(
          wrapAgentProfile(const AgentMobileVerifyScreen(), notifier: notifier));
      await tester.pump();

      expect(find.text('Incorrect code. Please try again.'), findsOneWidget);
    });
  });
}
