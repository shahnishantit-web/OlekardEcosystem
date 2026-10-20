import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:customer/modules/profile/screens/account_status_screen.dart';
import 'package:customer/modules/profile/state/profile_state.dart';
import '../helpers/profile_test_helpers.dart';

void main() {
  group('AccountStatusScreen — widget tests (T133)', () {
    testWidgets('shows loading while isSubmitting (status fetch in progress)',
        (tester) async {
      final notifier = StatefulNotifier(
        const ProfileLoaded(profile: fakeProfile, isSubmitting: true),
      );
      await tester.pumpWidget(wrapProfile(const AccountStatusScreen(),
          notifier: notifier));
      await tester.pump();

      expect(find.byType(CircularProgressIndicator), findsWidgets);
    });

    testWidgets('shows status badge when accountStatus is loaded',
        (tester) async {
      final notifier = StatefulNotifier(
        ProfileLoaded(
          profile: fakeProfile,
          accountStatus: fakeAccountStatus(),
        ),
      );
      await tester.pumpWidget(wrapProfile(const AccountStatusScreen(),
          notifier: notifier));
      await tester.pump();

      expect(find.textContaining('ACTIVE'), findsWidgets);
    });

    testWidgets('renders history list entries', (tester) async {
      final notifier = StatefulNotifier(
        ProfileLoaded(
          profile: fakeProfile,
          accountStatus: fakeAccountStatus(),
        ),
      );
      await tester.pumpWidget(wrapProfile(const AccountStatusScreen(),
          notifier: notifier));
      await tester.pump();

      // History tile shows status transition arrows
      expect(find.byIcon(Icons.arrow_forward), findsOneWidget);
    });

    testWidgets('shows empty history message when list is empty',
        (tester) async {
      final notifier = StatefulNotifier(
        ProfileLoaded(
          profile: fakeProfile,
          accountStatus: AccountStatusData(
            status: 'active',
            history: const [],
          ),
        ),
      );
      await tester.pumpWidget(wrapProfile(const AccountStatusScreen(),
          notifier: notifier));
      await tester.pump();

      expect(find.text('No history available.'), findsOneWidget);
    });

    testWidgets('shows error state with retry button on errorMessage',
        (tester) async {
      final notifier = StatefulNotifier(
        const ProfileLoaded(
          profile: fakeProfile,
          errorMessage: 'Could not load status.',
        ),
      );
      await tester.pumpWidget(wrapProfile(const AccountStatusScreen(),
          notifier: notifier));
      await tester.pump();

      expect(find.text('Could not load status.'), findsOneWidget);
      expect(find.byType(ElevatedButton), findsOneWidget);
    });

    testWidgets('shows closure obligations list when obligations are present',
        (tester) async {
      final notifier = StatefulNotifier(
        ProfileLoaded(
          profile: fakeProfile,
          accountStatus: AccountStatusData(
            status: 'active',
            history: const [],
            closureObligations: const ['outstanding_balance'],
          ),
        ),
      );
      await tester.pumpWidget(wrapProfile(const AccountStatusScreen(),
          notifier: notifier));
      await tester.pump();

      expect(find.textContaining('outstanding balance'), findsOneWidget);
    });

    testWidgets('accessibility: status section has a header semantics node',
        (tester) async {
      final notifier = StatefulNotifier(
        ProfileLoaded(
          profile: fakeProfile,
          accountStatus: fakeAccountStatus(),
        ),
      );
      await tester.pumpWidget(wrapProfile(const AccountStatusScreen(),
          notifier: notifier));
      await tester.pump();

      final headers = tester.semantics.nodesWith(isHeader: true).toList();
      expect(headers, isNotEmpty);
    });
  });
}
