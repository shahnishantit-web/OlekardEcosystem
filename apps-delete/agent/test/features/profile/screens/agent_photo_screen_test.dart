import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:image_picker_platform_interface/image_picker_platform_interface.dart';
import 'package:cross_file/cross_file.dart';
import 'package:agent/features/profile/screens/agent_photo_screen.dart';
import 'package:agent/features/profile/state/agent_profile_state.dart';
import '../helpers/agent_profile_test_helpers.dart';

// ── Fake ImagePickerPlatform implementations ──────────────────────────────────

class _SuccessPickerPlatform extends ImagePickerPlatform {
  @override
  Future<XFile?> getImageFromSource({
    required ImageSource source,
    ImagePickerOptions? options,
  }) async =>
      XFile('/fake/agent_photo.jpg');
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
  group('AgentPhotoScreen — widget tests (T140)', () {
    testWidgets('renders choose photo button in default state', (tester) async {
      await tester.pumpWidget(wrapAgentProfile(const AgentPhotoScreen()));
      await tester.pump();

      expect(find.text('Choose photo'), findsOneWidget);
    });

    testWidgets('CircleAvatar is shown', (tester) async {
      await tester.pumpWidget(wrapAgentProfile(const AgentPhotoScreen()));
      await tester.pump();

      expect(find.byType(CircleAvatar), findsWidgets);
    });

    testWidgets('shows loading widget while uploading', (tester) async {
      final notifier = StatefulNotifier(
        const AgentProfileLoaded(profile: fakeProfile, isSubmitting: true),
      );
      await tester
          .pumpWidget(wrapAgentProfile(const AgentPhotoScreen(), notifier: notifier));
      await tester.pump();

      expect(find.byType(CircularProgressIndicator), findsWidgets);
    });

    testWidgets('shows placeholder icon when no photo', (tester) async {
      await tester.pumpWidget(wrapAgentProfile(
        const AgentPhotoScreen(),
        notifier: StatefulNotifier(
          const AgentProfileLoaded(profile: fakeProfileNoPhoto),
        ),
      ));
      await tester.pump();

      expect(find.byIcon(Icons.person), findsOneWidget);
    });

    testWidgets('Upload button is disabled before a photo is selected',
        (tester) async {
      await tester.pumpWidget(wrapAgentProfile(const AgentPhotoScreen()));
      await tester.pump();

      final uploadButton = tester.widget<ElevatedButton>(
        find.widgetWithText(ElevatedButton, 'Upload'),
      );
      expect(uploadButton.onPressed, isNull);
    });

    testWidgets('choosing a photo enables the Upload button', (tester) async {
      ImagePickerPlatform.instance = _SuccessPickerPlatform();
      addTearDown(
          () => ImagePickerPlatform.instance = ImagePickerPlatform.instance);

      await tester.pumpWidget(wrapAgentProfile(const AgentPhotoScreen()));
      await tester.pump();

      await tester.tap(find.text('Choose photo'));
      await tester.pumpAndSettle();

      expect(
        tester
            .widget<ElevatedButton>(find.widgetWithText(ElevatedButton, 'Upload'))
            .onPressed,
        isNotNull,
      );

      final avatar = tester.widget<CircleAvatar>(find.byType(CircleAvatar));
      expect(avatar.backgroundImage, isA<FileImage>());
    });

    testWidgets('cancelling the picker leaves Upload button disabled',
        (tester) async {
      ImagePickerPlatform.instance = _CancelPickerPlatform();

      await tester.pumpWidget(wrapAgentProfile(const AgentPhotoScreen()));
      await tester.pump();

      await tester.tap(find.text('Choose photo'));
      await tester.pumpAndSettle();

      expect(
        tester
            .widget<ElevatedButton>(find.widgetWithText(ElevatedButton, 'Upload'))
            .onPressed,
        isNull,
      );
    });

    testWidgets('permission denied shows error SnackBar', (tester) async {
      ImagePickerPlatform.instance = _PermissionDeniedPickerPlatform();

      await tester.pumpWidget(wrapAgentProfile(const AgentPhotoScreen()));
      await tester.pump();

      await tester.tap(find.text('Choose photo'));
      await tester.pumpAndSettle();

      expect(
        find.text('Photo access denied. Please allow access in Settings.'),
        findsOneWidget,
      );

      expect(
        tester
            .widget<ElevatedButton>(find.widgetWithText(ElevatedButton, 'Upload'))
            .onPressed,
        isNull,
      );
    });
  });
}
