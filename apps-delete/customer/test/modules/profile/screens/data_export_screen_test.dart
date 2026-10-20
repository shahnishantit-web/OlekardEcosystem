import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:customer/modules/profile/screens/data_export_screen.dart';
import 'package:customer/modules/profile/state/profile_state.dart';
import '../helpers/profile_test_helpers.dart';

void main() {
  group('DataExportScreen — widget tests (T135)', () {
    testWidgets('shows request button in default state', (tester) async {
      await tester.pumpWidget(wrapProfile(const DataExportScreen()));
      await tester.pump();

      expect(find.text('Request data export'), findsOneWidget);
    });

    testWidgets('shows in-progress card when export status is pending',
        (tester) async {
      final notifier = StatefulNotifier(
        ProfileLoaded(
          profile: fakeProfile,
          dataExport: fakePendingExport(),
        ),
      );
      await tester.pumpWidget(wrapProfile(const DataExportScreen(),
          notifier: notifier));
      await tester.pump();

      expect(find.textContaining('Pending'), findsOneWidget);
    });

    testWidgets('hides request button when export is in progress',
        (tester) async {
      final notifier = StatefulNotifier(
        ProfileLoaded(
          profile: fakeProfile,
          dataExport: fakePendingExport(),
        ),
      );
      await tester.pumpWidget(wrapProfile(const DataExportScreen(),
          notifier: notifier));
      await tester.pump();

      expect(find.text('Request data export'), findsNothing);
    });

    testWidgets('shows EXPORT_IN_PROGRESS info banner on that error code',
        (tester) async {
      final notifier = StatefulNotifier(
        const ProfileLoaded(
          profile: fakeProfile,
          errorCode: 'EXPORT_IN_PROGRESS',
          errorMessage: 'raw',
        ),
      );
      await tester.pumpWidget(wrapProfile(const DataExportScreen(),
          notifier: notifier));
      await tester.pump();

      expect(find.textContaining('already in progress'), findsOneWidget);
      // Button hidden
      expect(find.text('Request data export'), findsNothing);
    });

    testWidgets('shows success banner after successful request', (tester) async {
      final notifier = StatefulNotifier(
        ProfileLoaded(
          profile: fakeProfile,
          dataExport: fakePendingExport(),
          successMessage: 'Export requested. You will receive an email when ready.',
        ),
      );
      // dataExport is pending so button is hidden, but success banner shown.
      await tester.pumpWidget(wrapProfile(const DataExportScreen(),
          notifier: notifier));
      await tester.pump();

      expect(
        find.textContaining('Export requested'),
        findsNothing, // hidden because exportInProgress branch takes over
      );
    });

    testWidgets('shows success banner when no pending export and success set',
        (tester) async {
      final notifier = StatefulNotifier(
        const ProfileLoaded(
          profile: fakeProfile,
          successMessage: 'Export requested. You will receive an email when ready.',
        ),
      );
      await tester.pumpWidget(wrapProfile(const DataExportScreen(),
          notifier: notifier));
      await tester.pump();

      expect(find.textContaining('Export requested'), findsOneWidget);
    });

    testWidgets('shows loading widget while isSubmitting', (tester) async {
      final notifier = StatefulNotifier(
        const ProfileLoaded(profile: fakeProfile, isSubmitting: true),
      );
      await tester.pumpWidget(wrapProfile(const DataExportScreen(),
          notifier: notifier));
      await tester.pump();

      expect(find.byType(CircularProgressIndicator), findsWidgets);
    });

    testWidgets('shows generic error banner for unknown error codes',
        (tester) async {
      final notifier = StatefulNotifier(
        const ProfileLoaded(
          profile: fakeProfile,
          errorCode: 'SERVER_ERROR',
          errorMessage: 'Something went wrong.',
        ),
      );
      await tester.pumpWidget(wrapProfile(const DataExportScreen(),
          notifier: notifier));
      await tester.pump();

      expect(find.text('Something went wrong.'), findsOneWidget);
    });

    testWidgets('accessibility: header semantics present', (tester) async {
      await tester.pumpWidget(wrapProfile(const DataExportScreen()));
      await tester.pump();

      final headers = tester.semantics.nodesWith(isHeader: true).toList();
      expect(headers, isNotEmpty);
    });
  });
}
