import 'dart:ui' show SemanticsFlag;
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:customer/modules/auth/state/auth_state.dart';
import 'package:customer/modules/auth/state/auth_state_notifier.dart';
import 'package:customer/modules/auth/screens/locked/account_locked_screen.dart';
import 'package:customer/modules/auth/storage/auth_token_storage.dart';
import 'package:customer/modules/auth/services/auth_api_service.dart';

class _LockedNotifier extends AuthStateNotifier {
  @override
  Future<AuthState> build() async => const AuthLocked(
        errorMessage: 'Too many failed attempts.',
      );
}

Widget _wrap(Widget child, {required AuthStateNotifier notifier}) {
  return ProviderScope(
    overrides: [
      authStateNotifierProvider.overrideWith(() => notifier),
      authTokenStorageProvider.overrideWith((_) => throw UnimplementedError()),
      authApiServiceProvider.overrideWith((_) => throw UnimplementedError()),
    ],
    child: MaterialApp(home: child),
  );
}

void main() {
  group('AccountLockedScreen — widget tests (T171)', () {
    testWidgets('renders locked title and message', (tester) async {
      final notifier = _LockedNotifier();
      await tester.pumpWidget(
          _wrap(const AccountLockedScreen(), notifier: notifier));
      await tester.pumpAndSettle();

      expect(find.text('Account Locked'), findsOneWidget);
      expect(find.text('Too many failed attempts.'), findsOneWidget);
    });

    testWidgets('shows recover via SMS CTA', (tester) async {
      final notifier = _LockedNotifier();
      await tester.pumpWidget(
          _wrap(const AccountLockedScreen(), notifier: notifier));
      await tester.pumpAndSettle();

      expect(find.text('Recover via SMS'), findsOneWidget);
    });

    testWidgets('shows sign out button', (tester) async {
      final notifier = _LockedNotifier();
      await tester.pumpWidget(
          _wrap(const AccountLockedScreen(), notifier: notifier));
      await tester.pumpAndSettle();

      expect(find.text('Sign out'), findsOneWidget);
    });

    // ── Accessibility (T173) ─────────────────────────────────────────────────

    testWidgets('lock icon has semantic label', (tester) async {
      final notifier = _LockedNotifier();
      await tester.pumpWidget(
          _wrap(const AccountLockedScreen(), notifier: notifier));
      await tester.pumpAndSettle();

      expect(find.bySemanticsLabel('Account locked'), findsOneWidget);
    });

    testWidgets('recover button has semantics button role', (tester) async {
      final notifier = _LockedNotifier();
      await tester.pumpWidget(
          _wrap(const AccountLockedScreen(), notifier: notifier));
      await tester.pumpAndSettle();

      final semantics = tester.getSemantics(find.text('Recover via SMS'));
      expect(semantics.hasFlag(SemanticsFlag.isButton), isTrue);
    });
  });
}
