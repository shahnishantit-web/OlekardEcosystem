import 'dart:async' show Completer;
import 'dart:ui' show SemanticsFlag;
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:customer/modules/auth/state/auth_state.dart';
import 'package:customer/modules/auth/state/auth_state_notifier.dart';
import 'package:customer/modules/auth/screens/registration/registration_screen.dart';
import 'package:customer/modules/auth/storage/auth_token_storage.dart';
import 'package:customer/modules/auth/services/auth_api_service.dart';

// ── Stub providers ────────────────────────────────────────────────────────────

class _AlwaysUnauthNotifier extends AuthStateNotifier {
  @override
  Future<AuthState> build() async => const AuthUnauthenticated();
}

class _LoadingNotifier extends AuthStateNotifier {
  @override
  // Never completes — keeps state in AsyncLoading so the loading widget is visible.
  Future<AuthState> build() => Completer<AuthState>().future;
}

class _ErrorNotifier extends AuthStateNotifier {
  @override
  Future<AuthState> build() async => const AuthUnauthenticated(
        errorCode: 'IDENTITY_007',
        errorMessage: 'Mobile number already registered.',
      );
}

Widget _wrap(Widget child, {AuthStateNotifier? notifier}) {
  return ProviderScope(
    overrides: [
      authStateNotifierProvider.overrideWith(
          () => notifier ?? _AlwaysUnauthNotifier()),
      authTokenStorageProvider.overrideWith((_) => throw UnimplementedError()),
      authApiServiceProvider.overrideWith((_) => throw UnimplementedError()),
    ],
    child: MaterialApp(home: child),
  );
}

// ── Tests (T171) ──────────────────────────────────────────────────────────────

void main() {
  group('RegistrationScreen — widget tests (T171)', () {
    testWidgets('renders mobile input and send button', (tester) async {
      await tester.pumpWidget(_wrap(const RegistrationScreen()));
      await tester.pump();

      expect(find.byType(TextFormField), findsOneWidget);
      expect(find.text('Send Code'), findsOneWidget);
    });

    testWidgets('shows loading widget while loading', (tester) async {
      await tester.pumpWidget(
          _wrap(const RegistrationScreen(), notifier: _LoadingNotifier()));
      await tester.pump();

      // LoadingWidget shows CircularProgressIndicator by default.
      expect(find.byType(CircularProgressIndicator), findsWidgets);
    });

    testWidgets('shows error banner when errorMessage is set', (tester) async {
      await tester.pumpWidget(
          _wrap(const RegistrationScreen(), notifier: _ErrorNotifier()));
      await tester.pump();

      expect(find.text('Mobile number already registered.'), findsOneWidget);
    });

    testWidgets('validation rejects empty mobile number', (tester) async {
      await tester.pumpWidget(_wrap(const RegistrationScreen()));
      await tester.pump();

      await tester.tap(find.text('Send Code'));
      await tester.pump();

      expect(find.text('Mobile number is required'), findsOneWidget);
    });

    // ── Accessibility (T173) ─────────────────────────────────────────────────

    testWidgets('mobile input has a semantics label', (tester) async {
      await tester.pumpWidget(_wrap(const RegistrationScreen()));
      await tester.pump();

      final semantics = tester.getSemantics(find.byType(TextFormField).first);
      expect(semantics.label, isNotEmpty);
    });

    testWidgets('send button has a semantics button role', (tester) async {
      await tester.pumpWidget(_wrap(const RegistrationScreen()));
      await tester.pump();

      final semantics = tester.getSemantics(find.text('Send Code'));
      expect(semantics.hasFlag(SemanticsFlag.isButton), isTrue);
    });
  });
}
