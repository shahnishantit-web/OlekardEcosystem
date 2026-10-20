import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agent/features/profile/screens/agent_status_screen.dart';
import 'package:agent/features/profile/state/agent_profile_state.dart';
import '../helpers/agent_profile_test_helpers.dart';

void main() {
  group('AgentStatusScreen — widget tests (T142)', () {
    testWidgets('shows loading while fetching status', (tester) async {
      final notifier = StatefulNotifier(
        const AgentProfileLoaded(profile: fakeProfile, isSubmitting: true),
      );
      await tester.pumpWidget(
          wrapAgentProfile(const AgentStatusScreen(), notifier: notifier));
      await tester.pump();

      expect(find.byType(CircularProgressIndicator), findsWidgets);
    });

    testWidgets('shows ACTIVE badge when status is active', (tester) async {
      final notifier = StatefulNotifier(
        AgentProfileLoaded(
          profile: fakeProfile,
          accountStatus: fakeAccountStatus(),
        ),
      );
      await tester.pumpWidget(
          wrapAgentProfile(const AgentStatusScreen(), notifier: notifier));
      await tester.pump();

      expect(find.textContaining('ACTIVE', findRichText: true), findsWidgets);
    });

    testWidgets('shows history entries', (tester) async {
      final notifier = StatefulNotifier(
        AgentProfileLoaded(
          profile: fakeProfile,
          accountStatus: fakeAccountStatus(),
        ),
      );
      await tester.pumpWidget(
          wrapAgentProfile(const AgentStatusScreen(), notifier: notifier));
      await tester.pump();

      // fakeAccountStatus() has one history entry: pending → active
      expect(find.text('pending'), findsOneWidget);
      expect(find.text('active'), findsOneWidget);
    });

    testWidgets('shows no-history message when history is empty',
        (tester) async {
      final notifier = StatefulNotifier(
        AgentProfileLoaded(
          profile: fakeProfile,
          accountStatus: const AgentAccountStatusData(
            status: 'active',
            history: [],
          ),
        ),
      );
      await tester.pumpWidget(
          wrapAgentProfile(const AgentStatusScreen(), notifier: notifier));
      await tester.pump();

      expect(find.text('No history available.'), findsOneWidget);
    });

    testWidgets('shows error with retry on error', (tester) async {
      final notifier = StatefulNotifier(
        const AgentProfileLoaded(
          profile: fakeProfile,
          errorMessage: 'Failed to load status.',
        ),
      );
      await tester.pumpWidget(
          wrapAgentProfile(const AgentStatusScreen(), notifier: notifier));
      await tester.pump();

      expect(find.text('Failed to load status.'), findsOneWidget);
      expect(find.byType(ElevatedButton), findsOneWidget);
    });

    testWidgets('does NOT show closure action buttons (agent cannot self-close)',
        (tester) async {
      final notifier = StatefulNotifier(
        AgentProfileLoaded(
          profile: fakeProfile,
          accountStatus: fakeAccountStatus(),
        ),
      );
      await tester.pumpWidget(
          wrapAgentProfile(const AgentStatusScreen(), notifier: notifier));
      await tester.pump();

      expect(find.text('Request closure'), findsNothing);
      expect(find.text('Cancel closure'), findsNothing);
    });

    testWidgets('accessibility: status header has semantics node',
        (tester) async {
      final notifier = StatefulNotifier(
        AgentProfileLoaded(
          profile: fakeProfile,
          accountStatus: fakeAccountStatus(),
        ),
      );
      await tester.pumpWidget(
          wrapAgentProfile(const AgentStatusScreen(), notifier: notifier));
      await tester.pump();

      final headerNodes =
          tester.semantics.nodesWith(isHeader: true).toList();
      expect(headerNodes, isNotEmpty);
    });
  });
}
