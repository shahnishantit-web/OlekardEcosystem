import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:image_picker_platform_interface/image_picker_platform_interface.dart';
import 'package:cross_file/cross_file.dart';
import 'package:customer/modules/profile/screens/profile_photo_screen.dart';
import 'package:customer/modules/profile/state/profile_state.dart';
import '../helpers/profile_test_helpers.dart';

// ── Fake ImagePickerPlatform implementations ──────────────────────────────────

class _SuccessPickerPlatform extends ImagePickerPlatform {
  @override
  Future<XFile?> getImageFromSource({
    required ImageSource source,
    ImagePickerOptions? options,
  }) async =>
      XFile('/fake/selected_photo.jpg');
}

class _CancelPickerPlatform extends ImagePickerPlatform {
  @override
  Future<XFile?> getImageFromSource({
    required ImageSource source,
    ImagePickerOptions? options,
  }) async =>
      null;
}

class _PermissionDeniedPickerPlatform extends ImagePickerPlatform {
  @override
  Future<XFile?> getImageFromSource({
    required ImageSource source,
    ImagePickerOptions? options,
  }) async =>
      throw PlatformException(code: 'photo_access_denied');
}

void main() {
  group('ProfilePhotoScreen — widget tests (T128, T253)', () {
    // ── Pre-T253 tests (T128) ───────────────────────────────────────────────

    testWidgets('renders choose photo button in default state', (tester) async {
      await tester.pumpWidget(wrapProfile(const ProfilePhotoScreen()));
      await tester.pump();

      expect(find.text('Choose photo'), findsOneWidget);
    });

    testWidgets('current photo CircleAvatar is shown when photoUrl is set',
        (tester) async {
      await tester.pumpWidget(wrapProfile(const ProfilePhotoScreen()));
      await tester.pump();

      expect(find.byType(CircleAvatar), findsWidgets);
    });

    testWidgets('shows loading widget while isSubmitting', (tester) async {
      final notifier = StatefulNotifier(
        const ProfileLoaded(profile: fakeProfile, isSubmitting: true),
      );
      await tester.pumpWidget(wrapProfile(const ProfilePhotoScreen(),
          notifier: notifier));
      await tester.pump();

      expect(find.byType(CircularProgressIndicator), findsWidgets);
    });

    testWidgets('shows no photo placeholder when profilePhotoUrl is null',
        (tester) async {
      await tester.pumpWidget(wrapProfile(
        const ProfilePhotoScreen(),
        notifier: StatefulNotifier(
          const ProfileLoaded(profile: fakeProfileNoPhoto),
        ),
      ));
      await tester.pump();

      expect(find.byIcon(Icons.person), findsOneWidget);
    });

    testWidgets('accessibility: choose photo button has semantics role',
        (tester) async {
      await tester.pumpWidget(wrapProfile(const ProfilePhotoScreen()));
      await tester.pump();

      final semantics = tester.getSemantics(find.text('Choose photo'));
      expect(semantics.hasAction(SemanticsAction.tap), isTrue);
    });

    // ── T253: real image_picker integration ────────────────────────────────

    testWidgets('Upload button is disabled before a photo is selected',
        (tester) async {
      await tester.pumpWidget(wrapProfile(const ProfilePhotoScreen()));
      await tester.pump();

      // Find the Upload ElevatedButton — its onPressed must be null when
      // _selected == null, which makes it visually and semantically disabled.
      final uploadButton = tester.widget<ElevatedButton>(
        find.widgetWithText(ElevatedButton, 'Upload'),
      );
      expect(uploadButton.onPressed, isNull);
    });

    testWidgets(
        'choosing a photo via picker enables the Upload button (T253)',
        (tester) async {
      ImagePickerPlatform.instance = _SuccessPickerPlatform();
      addTearDown(() {
        ImagePickerPlatform.instance = ImagePickerPlatform.instance;
      });

      await tester.pumpWidget(wrapProfile(const ProfilePhotoScreen()));
      await tester.pump();

      // Upload button starts disabled.
      expect(
        tester.widget<ElevatedButton>(
          find.widgetWithText(ElevatedButton, 'Upload'),
        ).onPressed,
        isNull,
      );

      // Tap "Choose photo" to invoke the real picker path (faked by platform).
      await tester.tap(find.text('Choose photo'));
      await tester.pumpAndSettle();

      // After a successful pick the Upload button must be enabled.
      expect(
        tester.widget<ElevatedButton>(
          find.widgetWithText(ElevatedButton, 'Upload'),
        ).onPressed,
        isNotNull,
      );

      // The CircleAvatar switches to FileImage (photo preview).
      final avatar = tester.widget<CircleAvatar>(find.byType(CircleAvatar));
      expect(avatar.backgroundImage, isA<FileImage>());
    });

    testWidgets('cancelling the picker leaves Upload button disabled',
        (tester) async {
      ImagePickerPlatform.instance = _CancelPickerPlatform();

      await tester.pumpWidget(wrapProfile(const ProfilePhotoScreen()));
      await tester.pump();

      await tester.tap(find.text('Choose photo'));
      await tester.pumpAndSettle();

      expect(
        tester.widget<ElevatedButton>(
          find.widgetWithText(ElevatedButton, 'Upload'),
        ).onPressed,
        isNull,
      );
    });

    testWidgets('permission denied shows error SnackBar', (tester) async {
      ImagePickerPlatform.instance = _PermissionDeniedPickerPlatform();

      await tester.pumpWidget(wrapProfile(const ProfilePhotoScreen()));
      await tester.pump();

      await tester.tap(find.text('Choose photo'));
      await tester.pumpAndSettle();

      expect(
        find.text('Photo access denied. Please allow access in Settings.'),
        findsOneWidget,
      );

      // Upload button must still be disabled — no photo was chosen.
      expect(
        tester.widget<ElevatedButton>(
          find.widgetWithText(ElevatedButton, 'Upload'),
        ).onPressed,
        isNull,
      );
    });
  });
}
