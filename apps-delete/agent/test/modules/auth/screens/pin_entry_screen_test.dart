import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agent/modules/auth/state/auth_state.dart';
import 'package:agent/modules/auth/state/auth_state_notifier.dart';
import 'package:agent/modules/auth/screens/pin/pin_entry_screen.dart';
import 'package:agent/modules/auth/screens/pin/pin_keypad.dart';
import 'package:agent/modules/auth/storage/auth_token_storage.dart';
import 'package:agent/modules/auth/services/auth_api_service.dart';

class _UnauthedNotifier extends AuthStateNotifier {
  @override
  Future<AuthState> build() async => const AuthUnauthenticated();

  String? lastIdentifier;
  String? lastCredential;

  @override
  Future<void> login({
    required String identifier,
    required String credential,
    required String deviceFingerprint,
    String credentialType = 'pin',
  }) async {
    lastIdentifier = identifier;
    lastCredential = credential;
  }
}

class _ErrorNotifier extends AuthStateNotifier {
  @override
  Future<AuthState> build() async => const AuthUnauthenticated(
        errorCode: 'IDENTITY_011',
        errorMessage: 'Invalid credentials.',
      );
}

class _OfflineNotifier extends AuthStateNotifier {
  @override
  Future<AuthState> build() async => const AuthUnauthenticated(
        errorCode: 'NETWORK_OFFLINE',
        errorMessage: 'No internet connection.',
      );
}

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

void main() {
  group('PinEntryScreen — widget tests (T176, T178)', () {
    testWidgets('shows PIN dots and keypad', (tester) async {
      final notifier = _UnauthedNotifier();
      await tester.pumpWidget(
          _wrap(const PinEntryScreen(identifier: '+26771234567'),
              notifier: notifier));
      await tester.pumpAndSettle();

      expect(find.byType(PinDots), findsOneWidget);
      expect(find.byType(PinKeypad), findsOneWidget);
    });

    testWidgets('tapping digits fills PIN dots and submits on 6th digit',
        (tester) async {
      final notifier = _UnauthedNotifier();
      await tester.pumpWidget(
          _wrap(const PinEntryScreen(identifier: '+26771234567'),
              notifier: notifier));
      await tester.pumpAndSettle();

      for (final digit in ['1', '2', '3', '4', '5', '6']) {
        await tester.tap(find.text(digit));
        await tester.pump();
      }

      expect(notifier.lastIdentifier, '+26771234567');
      expect(notifier.lastCredential, '123456');
    });

    testWidgets('shows error message on login failure', (tester) async {
      await tester.binding.setSurfaceSize(const Size(800, 1200));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      final notifier = _ErrorNotifier();
      await tester.pumpWidget(
          _wrap(const PinEntryScreen(identifier: '+26771234567'),
              notifier: notifier));
      await tester.pumpAndSettle();

      expect(find.text('Invalid credentials.'), findsOneWidget);
    });

    testWidgets('shows offline indicator for network errors', (tester) async {
      await tester.binding.setSurfaceSize(const Size(800, 1200));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      final notifier = _OfflineNotifier();
      await tester.pumpWidget(
          _wrap(const PinEntryScreen(identifier: '+26771234567'),
              notifier: notifier));
      await tester.pumpAndSettle();

      expect(find.byIcon(Icons.wifi_off), findsOneWidget);
    });

    testWidgets('keypad has no biometric button (agent scope)', (tester) async {
      final notifier = _UnauthedNotifier();
      await tester.pumpWidget(
          _wrap(const PinEntryScreen(identifier: '+26771234567'),
              notifier: notifier));
      await tester.pumpAndSettle();

      // Biometric icon must not appear in Agent PinKeypad.
      expect(find.byIcon(Icons.fingerprint), findsNothing);
    });

    testWidgets('PIN dots heading has Semantics header=true', (tester) async {
      final notifier = _UnauthedNotifier();
      await tester.pumpWidget(
          _wrap(const PinEntryScreen(identifier: '+26771234567'),
              notifier: notifier));
      await tester.pumpAndSettle();

      expect(
        find.byWidgetPredicate(
          (w) =>
              w is Semantics &&
              w.properties.header == true &&
              (w.child is Text) &&
              (w.child as Text).data == 'Enter your PIN',
        ),
        findsOneWidget,
      );
    });
  });
}
