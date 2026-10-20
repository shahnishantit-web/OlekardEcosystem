import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:customer/modules/profile/screens/profile_edit_screen.dart';
import 'package:customer/modules/profile/state/profile_state.dart';
import '../helpers/profile_test_helpers.dart';

void main() {
  group('ProfileEditScreen — widget tests (T127)', () {
    testWidgets('renders first_name and last_name form fields', (tester) async {
      await tester.pumpWidget(wrapProfile(const ProfileEditScreen()));
      await tester.pump();

      expect(find.byType(TextFormField), findsWidgets);
      expect(find.text('Save changes'), findsOneWidget);
    });

    testWidgets('pre-fills fields from loaded profile', (tester) async {
      await tester.pumpWidget(wrapProfile(const ProfileEditScreen()));
      await tester.pump();

      expect(find.widgetWithText(TextFormField, 'Ada'), findsOneWidget);
      expect(find.widgetWithText(TextFormField, 'Lovelace'), findsOneWidget);
    });

    testWidgets('shows loading widget while isSubmitting', (tester) async {
      final notifier = StatefulNotifier(
        const ProfileLoaded(profile: fakeProfile, isSubmitting: true),
      );
      await tester.pumpWidget(wrapProfile(const ProfileEditScreen(),
          notifier: notifier));
      await tester.pump();

      expect(find.byType(CircularProgressIndicator), findsWidgets);
    });

    testWidgets('shows error banner when errorMessage is set', (tester) async {
      final notifier = StatefulNotifier(
        const ProfileLoaded(
          profile: fakeProfile,
          errorMessage: 'Update failed.',
        ),
      );
      await tester.pumpWidget(wrapProfile(const ProfileEditScreen(),
          notifier: notifier));
      await tester.pump();

      expect(find.text('Update failed.'), findsOneWidget);
    });

    testWidgets('shows success banner when successMessage is set',
        (tester) async {
      final notifier = StatefulNotifier(
        const ProfileLoaded(
          profile: fakeProfile,
          successMessage: 'Profile updated successfully.',
        ),
      );
      await tester.pumpWidget(wrapProfile(const ProfileEditScreen(),
          notifier: notifier));
      await tester.pump();

      expect(find.text('Profile updated successfully.'), findsOneWidget);
    });

    testWidgets('validation rejects empty first name', (tester) async {
      await tester.pumpWidget(wrapProfile(const ProfileEditScreen()));
      await tester.pump();

      // Clear the first-name field and try to save.
      final firstNameField = find.byType(TextFormField).first;
      await tester.tap(firstNameField);
      await tester.enterText(firstNameField, '');
      await tester.tap(find.text('Save changes'));
      await tester.pump();

      expect(find.text('First name is required'), findsOneWidget);
    });

    testWidgets('accessibility: form fields have semantic labels',
        (tester) async {
      await tester.pumpWidget(wrapProfile(const ProfileEditScreen()));
      await tester.pump();

      final semantics = tester.getSemantics(find.byType(TextFormField).first);
      expect(semantics.label, isNotEmpty);
    });
  });
}
