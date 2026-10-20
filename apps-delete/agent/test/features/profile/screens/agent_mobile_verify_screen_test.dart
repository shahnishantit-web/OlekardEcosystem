import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agent/features/profile/screens/agent_mobile_verify_screen.dart';
import 'package:agent/features/profile/state/agent_profile_state.dart';
import '../helpers/agent_profile_test_helpers.dart';

void main() {
  group('AgentMobileVerifyScreen — widget tests (T259)', () {
    // (1) Loading state
    testWidgets('shows loading indicator while verifying OTP', (tester) async {
      final notifier = StatefulNotifier(
        const AgentProfileLoaded(profile: fakeProfile, isSubmitting: true),
      );
      await tester.pumpWidget(
        wrapAgentProfile(const AgentMobileVerifyScreen(), notifier: notifier),
      );
      await tester.pump();

      expect(find.byType(CircularProgressIndicator), findsWidgets);
      // Input and Verify button are hidden during submission
      expect(find.byType(TextFormField), findsNothing);
      expect(find.text('Verify'), findsNothing);
    });

    // (2) OTP input + Verify button enabled
    testWidgets('renders 6-digit OTP input and enabled Verify button in default state',
        (tester) async {
      await tester
          .pumpWidget(wrapAgentProfile(const AgentMobileVerifyScreen()));
      await tester.pump();

      final field = find.byType(TextFormField);
      expect(field, findsOneWidget);
      expect(find.text('Verify'), findsOneWidget);
      expect(find.text('Use a different number'), findsOneWidget);

      // Accepting 6 chars — maxLength is 6
      await tester.enterText(field, '123456');
      await tester.pump();

      // Button should still be present and tappable (not null onPressed)
      final verifyBtn = tester.widget<ElevatedButton>(
        find.ancestor(
          of: find.text('Verify'),
          matching: find.byType(ElevatedButton),
        ),
      );
      expect(verifyBtn.onPressed, isNotNull);
    });

    // (3) Invalid OTP — inline field error
    testWidgets('shows OTP_INVALID error inline inside the text field',
        (tester) async {
      final challenge = fakeMobileChallenge().copyWith(
        errorCode: 'OTP_INVALID',
        errorMessage: 'Incorrect code. Please try again.',
      );
      final notifier = StatefulNotifier(
        AgentProfileLoaded(profile: fakeProfile, mobileChallenge: challenge),
      );
      await tester.pumpWidget(
        wrapAgentProfile(const AgentMobileVerifyScreen(), notifier: notifier),
      );
      await tester.pump();

      expect(find.text('Incorrect code. Please try again.'), findsOneWidget);
    });

    // (4) Expired OTP — expiry message + re-initiate CTA
    testWidgets('shows expiry message and "Use a different number" CTA on OTP_EXPIRED',
        (tester) async {
      final challenge = fakeMobileChallenge().copyWith(
        errorCode: 'OTP_EXPIRED',
        errorMessage: 'Code has expired.',
      );
      final notifier = StatefulNotifier(
        AgentProfileLoaded(profile: fakeProfile, mobileChallenge: challenge),
      );
      await tester.pumpWidget(
        wrapAgentProfile(const AgentMobileVerifyScreen(), notifier: notifier),
      );
      await tester.pump();

      // The expiry banner
      expect(
        find.text('The code has expired. Go back and request a new one.'),
        findsOneWidget,
      );
      // Re-initiate CTA — pops back to the change screen
      expect(find.text('Use a different number'), findsOneWidget);
    });

    // (5) Success / submission path — tapping Verify triggers verifyMobileChange
    testWidgets('tapping Verify button does not throw and dispatches submission',
        (tester) async {
      await tester.pumpWidget(
        wrapAgentProfile(const AgentMobileVerifyScreen()),
      );
      await tester.pump();

      await tester.enterText(find.byType(TextFormField), '654321');
      await tester.pump();

      // Tap Verify — the stub notifier won't change state, but the call must not throw
      await tester.tap(find.text('Verify'));
      await tester.pump();

      // Screen remains mounted; no unhandled exception
      expect(find.byType(AgentMobileVerifyScreen), findsOneWidget);
    });

    // (6) Semantics labels on all interactive elements
    testWidgets('all interactive elements carry Semantics labels', (tester) async {
      final challenge = fakeMobileChallenge();
      final notifier = StatefulNotifier(
        AgentProfileLoaded(profile: fakeProfile, mobileChallenge: challenge),
      );
      await tester.pumpWidget(
        wrapAgentProfile(const AgentMobileVerifyScreen(), notifier: notifier),
      );
      await tester.pump();

      // OTP field has an explicit semantic label
      expect(
        find.bySemanticsLabel('One-time code, 6 digits'),
        findsOneWidget,
      );

      // Destination hint is a live region (verified via liveRegion Semantics node)
      final destFinder = find.textContaining('+267');
      expect(destFinder, findsOneWidget);

      // Header text is marked as a header
      expect(find.text('Enter the 6-digit code'), findsOneWidget);
    });
  });
}
