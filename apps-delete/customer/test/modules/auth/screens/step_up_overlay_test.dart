import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:customer/modules/auth/models/auth_models.dart';
import 'package:customer/modules/auth/screens/step_up/step_up_overlay.dart';
import 'package:customer/modules/auth/state/auth_state.dart';
import 'package:customer/modules/auth/state/auth_state_notifier.dart';
import 'package:customer/modules/auth/storage/auth_token_storage.dart';
import 'package:customer/modules/auth/services/auth_api_service.dart';

class _StepUpNotifier extends AuthStateNotifier {
  @override
  Future<AuthState> build() async => AuthStepUpPending(
        stepUpToken: 'step-up-tok-001',
        methods: ['totp', 'pin'],
        expiresAt: DateTime.now().add(const Duration(minutes: 5)),
        sessionId: 'session-001',
      );

  StepUpTokenData? returnedToken;

  @override
  Future<StepUpTokenData?> verifyStepUp({
    required String stepUpToken,
    required String method,
    required String credential,
  }) async {
    returnedToken = StepUpTokenData(
      stepUpAccessToken: 'verified-step-up',
      scope: 'payment:high-value',
      expiresIn: 300,
    );
    state = const AsyncData(AuthAuthenticated(sessionId: 'session-001'));
    return returnedToken;
  }
}

void main() {
  group('StepUpOverlay — widget tests (T171)', () {
    testWidgets('overlay renders title and verify button', (tester) async {
      final notifier = _StepUpNotifier();
      await tester.pumpWidget(
        ProviderScope(
          overrides: [
            authStateNotifierProvider.overrideWith(() => notifier),
            authTokenStorageProvider
                .overrideWith((_) => throw UnimplementedError()),
            authApiServiceProvider
                .overrideWith((_) => throw UnimplementedError()),
          ],
          child: MaterialApp(
            home: Builder(
              builder: (context) => Scaffold(
                body: Consumer(
                  builder: (ctx, ref, _) => ElevatedButton(
                    onPressed: () => showStepUpOverlay(
                      context,
                      stepUpToken: 'step-up-tok-001',
                      methods: ['totp', 'pin'],
                      expiresAt:
                          DateTime.now().add(const Duration(minutes: 5)),
                      ref: ref,
                    ),
                    child: const Text('Trigger step-up'),
                  ),
                ),
              ),
            ),
          ),
        ),
      );

      await tester.tap(find.text('Trigger step-up'));
      await tester.pumpAndSettle();

      expect(find.text('Additional verification required'), findsOneWidget);
      expect(find.text('Verify'), findsOneWidget);
    });

    testWidgets('method selector shows totp and pin chips', (tester) async {
      final notifier = _StepUpNotifier();
      await tester.pumpWidget(
        ProviderScope(
          overrides: [
            authStateNotifierProvider.overrideWith(() => notifier),
            authTokenStorageProvider
                .overrideWith((_) => throw UnimplementedError()),
            authApiServiceProvider
                .overrideWith((_) => throw UnimplementedError()),
          ],
          child: MaterialApp(
            home: Builder(
              builder: (context) => Scaffold(
                body: Consumer(
                  builder: (ctx, ref, _) => ElevatedButton(
                    onPressed: () => showStepUpOverlay(
                      context,
                      stepUpToken: 'tok',
                      methods: ['totp', 'pin'],
                      expiresAt:
                          DateTime.now().add(const Duration(minutes: 5)),
                      ref: ref,
                    ),
                    child: const Text('Open'),
                  ),
                ),
              ),
            ),
          ),
        ),
      );

      await tester.tap(find.text('Open'));
      await tester.pumpAndSettle();

      expect(find.text('Authenticator app'), findsOneWidget);
      expect(find.text('PIN'), findsOneWidget);
    });

    // ── Spec constraint: overlay MUST NOT navigate away (US-AUTH-037) ────────

    testWidgets('overlay is not dismissible by drag', (tester) async {
      final notifier = _StepUpNotifier();
      await tester.pumpWidget(
        ProviderScope(
          overrides: [
            authStateNotifierProvider.overrideWith(() => notifier),
            authTokenStorageProvider
                .overrideWith((_) => throw UnimplementedError()),
            authApiServiceProvider
                .overrideWith((_) => throw UnimplementedError()),
          ],
          child: MaterialApp(
            home: Builder(
              builder: (context) => Scaffold(
                body: Consumer(
                  builder: (ctx, ref, _) => ElevatedButton(
                    onPressed: () => showStepUpOverlay(
                      context,
                      stepUpToken: 'tok',
                      methods: ['totp'],
                      expiresAt:
                          DateTime.now().add(const Duration(minutes: 5)),
                      ref: ref,
                    ),
                    child: const Text('Open'),
                  ),
                ),
              ),
            ),
          ),
        ),
      );

      await tester.tap(find.text('Open'));
      await tester.pumpAndSettle();

      // Try to dismiss by tapping outside — isDismissible: false should prevent it.
      await tester.tapAt(const Offset(0, 100));
      await tester.pumpAndSettle();

      // Overlay still present.
      expect(find.text('Additional verification required'), findsOneWidget);
    });
  });
}
