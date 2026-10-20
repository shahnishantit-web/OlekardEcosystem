import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agent/features/profile/screens/agent_profile_view_screen.dart';
import 'package:agent/features/profile/state/agent_profile_state.dart';
import '../helpers/agent_profile_test_helpers.dart';

void main() {
  group('AgentProfileViewScreen — widget tests (T138)', () {
    testWidgets('renders display name when loaded', (tester) async {
      await tester.pumpWidget(wrapAgentProfile(const AgentProfileViewScreen()));
      await tester.pump();

      expect(find.text('Grace Hopper'), findsOneWidget);
    });

    testWidgets('shows loading indicator while profile is loading',
        (tester) async {
      await tester.pumpWidget(wrapAgentProfile(
        const AgentProfileViewScreen(),
        notifier: AlwaysLoadingNotifier(),
      ));
      await tester.pump();

      expect(find.byType(CircularProgressIndicator), findsWidgets);
    });

    testWidgets('shows error message with retry on AgentProfileError',
        (tester) async {
      await tester.pumpWidget(wrapAgentProfile(
        const AgentProfileViewScreen(),
        notifier: ErrorNotifier(),
      ));
      await tester.pump();

      expect(find.text('Something went wrong.'), findsOneWidget);
      expect(find.byType(ElevatedButton), findsOneWidget);
    });

    testWidgets('shows CircleAvatar when profilePhotoUrl is set', (tester) async {
      await tester.pumpWidget(wrapAgentProfile(const AgentProfileViewScreen()));
      await tester.pump();

      expect(find.byType(CircleAvatar), findsWidgets);
    });

    testWidgets('shows account status badge', (tester) async {
      await tester.pumpWidget(wrapAgentProfile(const AgentProfileViewScreen()));
      await tester.pump();

      expect(find.textContaining('ACTIVE', findRichText: true), findsWidgets);
    });

    testWidgets('does NOT show email change navigation link', (tester) async {
      // Email change is not in agent scope
      await tester.pumpWidget(wrapAgentProfile(const AgentProfileViewScreen()));
      await tester.pump();

      expect(find.text('Change email'), findsNothing);
    });

    testWidgets('shows change mobile navigation link', (tester) async {
      await tester.pumpWidget(wrapAgentProfile(const AgentProfileViewScreen()));
      await tester.pump();

      expect(find.text('Change mobile number'), findsOneWidget);
    });

    testWidgets('accessibility: screen has a header semantics node',
        (tester) async {
      await tester.pumpWidget(wrapAgentProfile(const AgentProfileViewScreen()));
      await tester.pump();

      final headerNodes =
          tester.semantics.nodesWith(isHeader: true).toList();
      expect(headerNodes, isNotEmpty);
    });
  });
}
