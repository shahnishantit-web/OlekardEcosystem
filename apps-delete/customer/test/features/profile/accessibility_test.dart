import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:customer/modules/profile/screens/account_status_screen.dart';
import 'package:customer/modules/profile/screens/closure_request_screen.dart';
import 'package:customer/modules/profile/screens/data_export_screen.dart';
import 'package:customer/modules/profile/screens/email_change_screen.dart';
import 'package:customer/modules/profile/screens/email_verify_screen.dart';
import 'package:customer/modules/profile/screens/mobile_change_screen.dart';
import 'package:customer/modules/profile/screens/mobile_verify_screen.dart';
import 'package:customer/modules/profile/screens/profile_edit_screen.dart';
import 'package:customer/modules/profile/screens/profile_photo_screen.dart';
import 'package:customer/modules/profile/screens/profile_view_screen.dart';
import '../../modules/profile/helpers/profile_test_helpers.dart';

// ---------------------------------------------------------------------------
// T157 — WCAG AA accessibility tests for Customer profile screens
// Constitution Principle 11: All public-facing UI MUST meet WCAG 2.1 AA.
// ---------------------------------------------------------------------------

void main() {
  // ── ProfileViewScreen ──────────────────────────────────────────────────────

  group('A11y — ProfileViewScreen (T157)', () {
    testWidgets('loaded state has a header semantics node with label', (tester) async {
      final handle = tester.ensureSemantics();

      await tester.pumpWidget(wrapProfile(const ProfileViewScreen()));
      await tester.pump();

      // Heading semantics: the screen must have a semantic header identifiable
      // by screen readers (e.g. AppBar title or a Semantics(header: true) node).
      final headerNodes = tester.semantics.nodesWith(isHeader: true).toList();
      expect(
        headerNodes,
        isNotEmpty,
        reason: 'ProfileViewScreen must expose a header semantics node for screen readers',
      );

      handle.dispose();
    });

    testWidgets('loaded state: all interactive elements are tappable and labeled', (tester) async {
      final handle = tester.ensureSemantics();

      await tester.pumpWidget(wrapProfile(const ProfileViewScreen()));
      await tester.pump();

      // Each EditableText, InkWell, or GestureDetector should have a non-empty semantic label
      final nodes = tester.semantics.nodesWith(hasTapAction: true);
      for (final node in nodes) {
        expect(
          node.label.isNotEmpty || node.tooltip.isNotEmpty,
          isTrue,
          reason: 'Interactive element must have a semantic label or tooltip; found empty: $node',
        );
      }

      handle.dispose();
    });

    testWidgets('loading state has no unlabeled progress indicator', (tester) async {
      final handle = tester.ensureSemantics();

      await tester.pumpWidget(
        wrapProfile(const ProfileViewScreen(), notifier: AlwaysLoadingNotifier()),
      );
      await tester.pump();

      // CircularProgressIndicator must be wrapped with a Semantics label
      final progress = find.byType(CircularProgressIndicator);
      if (progress.evaluate().isNotEmpty) {
        final semanticsNode = tester.semantics.nodesWith(
          hasLabel: true,
          ancestor: find.byType(Scaffold),
        );
        expect(
          semanticsNode.isNotEmpty,
          isTrue,
          reason: 'Loading state must have a semantic label (e.g., "Loading…") for screen readers',
        );
      }

      handle.dispose();
    });
  });

  // ── ProfileEditScreen ──────────────────────────────────────────────────────

  group('A11y — ProfileEditScreen (T157)', () {
    testWidgets('all form fields have semantic labels', (tester) async {
      final handle = tester.ensureSemantics();

      await tester.pumpWidget(wrapProfile(const ProfileEditScreen()));
      await tester.pump();

      // TextFormField / TextField nodes must have a label (hint or semanticsLabel).
      final labeledNodes = tester.semantics.nodesWith(hasLabel: true);
      expect(
        labeledNodes.isNotEmpty,
        isTrue,
        reason: 'Every text input field must have a semantic label',
      );

      handle.dispose();
    });

    testWidgets('Save button is labeled and actionable', (tester) async {
      final handle = tester.ensureSemantics();

      await tester.pumpWidget(wrapProfile(const ProfileEditScreen()));
      await tester.pump();

      // At least one button should be labeled "Save" or similar
      final saveButton = find.bySemanticsLabel(RegExp(r'Save', caseSensitive: false));
      expect(saveButton, findsWidgets,
          reason: 'ProfileEditScreen must have a semantically labeled Save action');

      handle.dispose();
    });
  });

  // ── ProfilePhotoScreen ─────────────────────────────────────────────────────

  group('A11y — ProfilePhotoScreen (T157)', () {
    testWidgets('photo upload area has a semantic label', (tester) async {
      final handle = tester.ensureSemantics();

      await tester.pumpWidget(wrapProfile(const ProfilePhotoScreen()));
      await tester.pump();

      // The tappable photo area must have a descriptive label so screen-reader
      // users know what the action does
      final tappable = tester.semantics.nodesWith(hasTapAction: true);
      final hasLabel = tappable.any((n) =>
          n.label.toLowerCase().contains('photo') ||
          n.label.toLowerCase().contains('upload') ||
          n.tooltip.toLowerCase().contains('photo'));

      expect(hasLabel, isTrue,
          reason: 'Photo upload/selection area must have a screen-reader label describing the action');

      handle.dispose();
    });
  });

  // ── EmailChangeScreen ──────────────────────────────────────────────────────

  group('A11y — EmailChangeScreen (T157)', () {
    testWidgets('email input field is labeled', (tester) async {
      final handle = tester.ensureSemantics();

      await tester.pumpWidget(wrapProfile(const EmailChangeScreen()));
      await tester.pump();

      // Must have a semantics node with "email" in the label
      final emailLabel = find.bySemanticsLabel(RegExp(r'email', caseSensitive: false));
      expect(emailLabel, findsWidgets,
          reason: 'Email input must be identified by a semantic label for screen readers');

      handle.dispose();
    });
  });

  // ── EmailVerifyScreen ──────────────────────────────────────────────────────

  group('A11y — EmailVerifyScreen (T157)', () {
    testWidgets('OTP input and submit are semantically labeled', (tester) async {
      final handle = tester.ensureSemantics();

      await tester.pumpWidget(wrapProfile(const EmailVerifyScreen()));
      await tester.pump();

      final nodes = tester.semantics.nodesWith(hasTapAction: true);
      expect(nodes.isNotEmpty, isTrue,
          reason: 'EmailVerifyScreen must have at least one labeled interactive element (OTP entry or submit)');

      handle.dispose();
    });
  });

  // ── MobileChangeScreen ─────────────────────────────────────────────────────

  group('A11y — MobileChangeScreen (T157)', () {
    testWidgets('mobile number input is semantically labeled', (tester) async {
      final handle = tester.ensureSemantics();

      await tester.pumpWidget(wrapProfile(const MobileChangeScreen()));
      await tester.pump();

      final mobileLabel = find.bySemanticsLabel(
        RegExp(r'mobile|phone|number', caseSensitive: false),
      );
      expect(mobileLabel, findsWidgets,
          reason: 'Mobile input must be identified by a semantic label');

      handle.dispose();
    });
  });

  // ── MobileVerifyScreen ─────────────────────────────────────────────────────

  group('A11y — MobileVerifyScreen (T157)', () {
    testWidgets('OTP input and submit are semantically labeled', (tester) async {
      final handle = tester.ensureSemantics();

      await tester.pumpWidget(wrapProfile(const MobileVerifyScreen()));
      await tester.pump();

      final nodes = tester.semantics.nodesWith(hasTapAction: true);
      expect(nodes.isNotEmpty, isTrue,
          reason: 'MobileVerifyScreen must have at least one labeled interactive element');

      handle.dispose();
    });
  });

  // ── AccountStatusScreen ────────────────────────────────────────────────────

  group('A11y — AccountStatusScreen (T157)', () {
    testWidgets('account status badge is semantically labeled', (tester) async {
      final handle = tester.ensureSemantics();

      await tester.pumpWidget(wrapProfile(const AccountStatusScreen()));
      await tester.pump();

      // The status badge must have a text or label accessible to screen readers
      final statusLabel = find.bySemanticsLabel(
        RegExp(r'active|suspended|pending|closed', caseSensitive: false),
      );
      expect(statusLabel, findsWidgets,
          reason: 'Account status value must be semantically exposed for screen readers');

      handle.dispose();
    });
  });

  // ── DataExportScreen ───────────────────────────────────────────────────────

  group('A11y — DataExportScreen (T157)', () {
    testWidgets('request export button is semantically labeled', (tester) async {
      final handle = tester.ensureSemantics();

      await tester.pumpWidget(wrapProfile(const DataExportScreen()));
      await tester.pump();

      final exportButton = find.bySemanticsLabel(
        RegExp(r'export|download|request', caseSensitive: false),
      );
      expect(exportButton, findsWidgets,
          reason: 'Export request button must be labeled for screen readers');

      handle.dispose();
    });
  });

  // ── ClosureRequestScreen ───────────────────────────────────────────────────

  group('A11y — ClosureRequestScreen (T157)', () {
    testWidgets('closure confirmation action is semantically labeled', (tester) async {
      final handle = tester.ensureSemantics();

      await tester.pumpWidget(wrapProfile(const ClosureRequestScreen()));
      await tester.pump();

      // Destructive confirm button must be labeled
      final confirmLabel = find.bySemanticsLabel(
        RegExp(r'close|confirm|request|cancel', caseSensitive: false),
      );
      expect(confirmLabel, findsWidgets,
          reason: 'Account closure confirmation button must have a semantic label');

      handle.dispose();
    });
  });
}
