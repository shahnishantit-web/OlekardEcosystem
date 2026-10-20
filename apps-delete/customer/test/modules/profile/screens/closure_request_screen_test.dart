import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:customer/modules/profile/models/profile_models.dart';
import 'package:customer/modules/profile/screens/closure_request_screen.dart';
import 'package:customer/modules/profile/state/profile_state.dart';
import '../helpers/profile_test_helpers.dart';

const _pendingProfile = ProfileData(
  userId: 'user-001',
  firstName: 'Ada',
  lastName: 'Lovelace',
  email: 'ada@example.com',
  mobileNumber: '+26771234567',
  accountStatus: 'pending_closure',
);

void main() {
  group('ClosureRequestScreen — widget tests (T134)', () {
    testWidgets('shows default request body when account is active',
        (tester) async {
      await tester.pumpWidget(wrapProfile(const ClosureRequestScreen()));
      await tester.pump();

      expect(find.text('Close your account'), findsOneWidget);
      expect(find.text('Request account closure'), findsOneWidget);
    });

    testWidgets('shows pending closure body when status is pending_closure',
        (tester) async {
      final notifier = StatefulNotifier(
        const ProfileLoaded(profile: _pendingProfile),
      );
      await tester.pumpWidget(wrapProfile(const ClosureRequestScreen(),
          notifier: notifier));
      await tester.pump();

      expect(find.text('Closure requested'), findsOneWidget);
      expect(find.text('Cancel closure request'), findsOneWidget);
    });

    testWidgets('shows obligations list when closure is blocked',
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
      await tester.pumpWidget(wrapProfile(const ClosureRequestScreen(),
          notifier: notifier));
      await tester.pump();

      expect(find.textContaining('outstanding balance'), findsOneWidget);
    });

    testWidgets('request closure button disabled when obligations exist',
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
      await tester.pumpWidget(wrapProfile(const ClosureRequestScreen(),
          notifier: notifier));
      await tester.pump();

      final btn = tester.widget<ElevatedButton>(
        find.widgetWithText(ElevatedButton, 'Request account closure'),
      );
      expect(btn.onPressed, isNull);
    });

    testWidgets('confirmation dialog appears when close button is tapped',
        (tester) async {
      await tester.pumpWidget(wrapProfile(const ClosureRequestScreen()));
      await tester.pump();

      await tester.tap(find.text('Request account closure'));
      await tester.pump();

      expect(find.byType(AlertDialog), findsOneWidget);
      expect(find.text('Are you sure?'), findsOneWidget);
    });

    testWidgets('shows loading widget while isSubmitting', (tester) async {
      final notifier = StatefulNotifier(
        const ProfileLoaded(profile: fakeProfile, isSubmitting: true),
      );
      await tester.pumpWidget(wrapProfile(const ClosureRequestScreen(),
          notifier: notifier));
      await tester.pump();

      expect(find.byType(CircularProgressIndicator), findsWidgets);
    });

    testWidgets('shows error banner in pending body', (tester) async {
      final notifier = StatefulNotifier(
        const ProfileLoaded(
          profile: _pendingProfile,
          errorMessage: 'Cancellation failed.',
        ),
      );
      await tester.pumpWidget(wrapProfile(const ClosureRequestScreen(),
          notifier: notifier));
      await tester.pump();

      expect(find.text('Cancellation failed.'), findsOneWidget);
    });

    testWidgets('accessibility: header semantics present in default body',
        (tester) async {
      await tester.pumpWidget(wrapProfile(const ClosureRequestScreen()));
      await tester.pump();

      final headers = tester.semantics.nodesWith(isHeader: true).toList();
      expect(headers, isNotEmpty);
    });
  });
}
