import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agent/modules/auth/state/auth_state.dart';
import 'package:agent/modules/auth/state/auth_state_notifier.dart';
import 'package:agent/modules/auth/screens/registration/registration_screen.dart';
import 'package:agent/modules/auth/storage/auth_token_storage.dart';
import 'package:agent/modules/auth/services/auth_api_service.dart';

// ── Stub notifiers ────────────────────────────────────────────────────────────

class _UnauthedNotifier extends AuthStateNotifier {
  @override
  Future<AuthState> build() async => const AuthUnauthenticated();

  String? lastMobile;

  @override
  Future<void> initiateRegistration(String mobileNumber) async {
    lastMobile = mobileNumber;
  }
}

class _ErrorNotifier extends AuthStateNotifier {
  @override
  Future<AuthState> build() async => const AuthUnauthenticated(
        errorCode: 'IDENTITY_001',
        errorMessage: 'Mobile number already registered.',
      );
}

class _OfflineNotifier extends AuthStateNotifier {
  @override
  Future<AuthState> build() async => const AuthUnauthenticated(
        errorCode: 'NETWORK_OFFLINE',
        errorMessage: 'No internet connection. Check your network and try again.',
      );
}

// ── Helper ───────────────────────────────────────────────────────────────────

Widget _wrap(Widget child, {required AuthStateNotifier notifier}) {
  return ProviderScope(
    overrides: [
      authStateNotifierProvider.overrideWith(() => notifier),
      authTokenStorageProvider.overrideWith((_) => throw UnimplementedError()),
      authApiServiceProvider.overrideWith((_) => throw UnimplementedError()),
    ],
    child: MaterialApp(
      theme: ThemeData(splashFactory: NoSplash.splashFactory),
      home: child,
    ),
  );
}

// ── Tests ─────────────────────────────────────────────────────────────────────

void main() {
  group('RegistrationScreen — widget tests (T176, T178)', () {
    testWidgets('shows mobile number form by default', (tester) async {
      final notifier = _UnauthedNotifier();
      await tester.pumpWidget(
          _wrap(const RegistrationScreen(), notifier: notifier));
      await tester.pumpAndSettle();

      expect(find.text('Enter your mobile number'), findsOneWidget);
      expect(find.text('Send Code'), findsOneWidget);
      expect(find.byType(TextFormField), findsOneWidget);
    });

    testWidgets('calls initiateRegistration with entered mobile', (tester) async {
      final notifier = _UnauthedNotifier();
      await tester.pumpWidget(
          _wrap(const RegistrationScreen(), notifier: notifier));
      await tester.pumpAndSettle();

      await tester.enterText(find.byType(TextFormField), '+26771234567');
      await tester.tap(find.text('Send Code'));
      await tester.pump();

      expect(notifier.lastMobile, '+26771234567');
    });

    testWidgets('shows error banner when state has error', (tester) async {
      final notifier = _ErrorNotifier();
      await tester.pumpWidget(
          _wrap(const RegistrationScreen(), notifier: notifier));
      await tester.pumpAndSettle();

      expect(find.text('Mobile number already registered.'), findsOneWidget);
    });

    testWidgets('shows wifi_off icon for offline errors', (tester) async {
      final notifier = _OfflineNotifier();
      await tester.pumpWidget(
          _wrap(const RegistrationScreen(), notifier: notifier));
      await tester.pumpAndSettle();

      expect(find.byIcon(Icons.wifi_off), findsOneWidget);
    });

    testWidgets('shows validation error for empty mobile field', (tester) async {
      final notifier = _UnauthedNotifier();
      await tester.pumpWidget(
          _wrap(const RegistrationScreen(), notifier: notifier));
      await tester.pumpAndSettle();

      await tester.tap(find.text('Send Code'));
      await tester.pumpAndSettle();

      expect(find.text('Mobile number is required'), findsOneWidget);
    });

    testWidgets('heading has Semantics header=true', (tester) async {
      final notifier = _UnauthedNotifier();
      await tester.pumpWidget(
          _wrap(const RegistrationScreen(), notifier: notifier));
      await tester.pumpAndSettle();

      expect(
        find.byWidgetPredicate(
          (w) =>
              w is Semantics &&
              w.properties.header == true &&
              (w.child is Text) &&
              (w.child as Text).data == 'Enter your mobile number',
        ),
        findsOneWidget,
      );
    });
  });
}
