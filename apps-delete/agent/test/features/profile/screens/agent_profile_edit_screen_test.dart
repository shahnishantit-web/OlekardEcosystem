import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agent/features/profile/screens/agent_profile_edit_screen.dart';
import 'package:agent/features/profile/state/agent_profile_state.dart';
import '../helpers/agent_profile_test_helpers.dart';

void main() {
  group('AgentProfileEditScreen — widget tests (T139)', () {
    testWidgets('renders first and last name fields pre-filled from profile',
        (tester) async {
      await tester
          .pumpWidget(wrapAgentProfile(const AgentProfileEditScreen()));
      await tester.pump();

      expect(find.widgetWithText(TextFormField, 'Grace'), findsOneWidget);
      expect(find.widgetWithText(TextFormField, 'Hopper'), findsOneWidget);
    });

    testWidgets('shows loading widget while submitting', (tester) async {
      final notifier = StatefulNotifier(
        const AgentProfileLoaded(profile: fakeProfile, isSubmitting: true),
      );
      await tester.pumpWidget(
          wrapAgentProfile(const AgentProfileEditScreen(), notifier: notifier));
      await tester.pump();

      expect(find.byType(CircularProgressIndicator), findsWidgets);
    });

    testWidgets('shows error banner on error', (tester) async {
      final notifier = StatefulNotifier(
        const AgentProfileLoaded(
          profile: fakeProfile,
          errorMessage: 'Update failed.',
        ),
      );
      await tester.pumpWidget(
          wrapAgentProfile(const AgentProfileEditScreen(), notifier: notifier));
      await tester.pump();

      expect(find.text('Update failed.'), findsOneWidget);
    });

    testWidgets('shows success banner on success', (tester) async {
      final notifier = StatefulNotifier(
        const AgentProfileLoaded(
          profile: fakeProfile,
          successMessage: 'Profile updated successfully.',
        ),
      );
      await tester.pumpWidget(
          wrapAgentProfile(const AgentProfileEditScreen(), notifier: notifier));
      await tester.pump();

      expect(find.text('Profile updated successfully.'), findsOneWidget);
    });

    testWidgets('first name field fails validation when empty', (tester) async {
      await tester
          .pumpWidget(wrapAgentProfile(const AgentProfileEditScreen()));
      await tester.pump();

      // Clear first name field
      await tester.enterText(
          find.widgetWithText(TextFormField, 'Grace'), '');
      await tester.tap(find.text('Save changes'));
      await tester.pump();

      expect(find.text('First name is required'), findsOneWidget);
    });

    testWidgets('accessibility: form fields have semantic labels',
        (tester) async {
      await tester
          .pumpWidget(wrapAgentProfile(const AgentProfileEditScreen()));
      await tester.pump();

      expect(find.bySemanticsLabel('First name'), findsWidgets);
      expect(find.bySemanticsLabel('Last name'), findsWidgets);
    });
  });
}
