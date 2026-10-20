import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:customer/modules/profile/screens/profile_view_screen.dart';
import 'package:customer/modules/profile/state/profile_state.dart';
import '../helpers/profile_test_helpers.dart';

void main() {
  group('ProfileViewScreen — widget tests (T126)', () {
    testWidgets('renders display name when loaded', (tester) async {
      await tester.pumpWidget(wrapProfile(const ProfileViewScreen()));
      await tester.pump();

      expect(find.text('Ada Lovelace'), findsOneWidget);
    });

    testWidgets('shows CircularProgressIndicator while loading', (tester) async {
      await tester.pumpWidget(
        wrapProfile(
          const ProfileViewScreen(),
          notifier: AlwaysLoadingNotifier(),
        ),
      );
      await tester.pump();

      expect(find.byType(CircularProgressIndicator), findsWidgets);
    });

    testWidgets('shows error message with retry on ProfileError', (tester) async {
      await tester.pumpWidget(
        wrapProfile(
          const ProfileViewScreen(),
          notifier: ErrorNotifier(),
        ),
      );
      await tester.pump();

      expect(find.text('Something went wrong.'), findsOneWidget);
      expect(find.byType(ElevatedButton), findsOneWidget);
    });

    testWidgets('shows CircleAvatar when profilePhotoUrl is set',
        (tester) async {
      await tester.pumpWidget(wrapProfile(const ProfileViewScreen()));
      await tester.pump();

      expect(find.byType(CircleAvatar), findsWidgets);
    });

    testWidgets('shows account status badge', (tester) async {
      await tester.pumpWidget(wrapProfile(const ProfileViewScreen()));
      await tester.pump();

      // AppBadge renders a Text; 'ACTIVE' is expected for fakeProfile status.
      expect(find.textContaining('ACTIVE', findRichText: true), findsWidgets);
    });

    testWidgets('accessibility: screen has a header semantics node',
        (tester) async {
      await tester.pumpWidget(wrapProfile(const ProfileViewScreen()));
      await tester.pump();

      final headerNodes = tester.semantics
          .nodesWith(isHeader: true)
          .toList();
      expect(headerNodes, isNotEmpty);
    });
  });
}
