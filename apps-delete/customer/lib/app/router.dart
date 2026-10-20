import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../modules/auth/screens/biometric/biometric_screen.dart';
import '../modules/auth/screens/locked/account_locked_screen.dart';
import '../modules/auth/screens/mfa/mfa_disable_screen.dart';
import '../modules/auth/screens/mfa/mfa_enable_screen.dart';
import '../modules/auth/screens/mfa/mfa_verify_screen.dart';
import '../modules/auth/screens/otp/otp_verify_screen.dart';
import '../modules/auth/screens/pin/pin_change_screen.dart';
import '../modules/auth/screens/pin/pin_create_screen.dart';
import '../modules/auth/screens/pin/pin_entry_screen.dart';
import '../modules/auth/screens/pin/pin_reset_screen.dart';
import '../modules/auth/screens/pin/pin_unblock_screen.dart';
import '../modules/auth/screens/registration/registration_screen.dart';
import '../modules/auth/screens/sessions/sessions_screen.dart';
import '../modules/auth/state/auth_state.dart';
import '../modules/auth/state/auth_state_notifier.dart';
import '../shared/widgets/loading.dart';

final routerProvider = Provider<GoRouter>((ref) {
  final notifier = _RouterNotifier(ref);

  return GoRouter(
    refreshListenable: notifier,
    redirect: notifier.redirect,
    initialLocation: '/auth/register',
    routes: [
      // ── Auth routes ─────────────────────────────────────────────────────
      GoRoute(
        path: '/auth/register',
        builder: (_, __) => const RegistrationScreen(),
      ),
      GoRoute(
        path: '/auth/login',
        builder: (_, state) => PinEntryScreen(
          identifier: state.uri.queryParameters['identifier'] ?? '',
        ),
      ),
      GoRoute(
        path: '/auth/otp',
        builder: (_, __) => const OtpVerifyScreen(),
      ),
      GoRoute(
        path: '/auth/pin/set',
        builder: (_, __) => const PinCreateScreen(),
      ),
      GoRoute(
        path: '/auth/pin/change',
        builder: (_, __) => const PinChangeScreen(),
      ),
      GoRoute(
        path: '/auth/pin/reset',
        builder: (_, __) => const PinResetScreen(),
      ),
      GoRoute(
        path: '/auth/pin/unblock',
        builder: (_, __) => const PinUnblockScreen(),
      ),
      GoRoute(
        path: '/auth/mfa/verify',
        builder: (_, __) => const MfaVerifyScreen(),
      ),
      GoRoute(
        path: '/auth/mfa/enable',
        builder: (_, __) => const MfaEnableScreen(),
      ),
      GoRoute(
        path: '/auth/mfa/disable',
        builder: (_, __) => const MfaDisableScreen(),
      ),
      GoRoute(
        path: '/auth/biometric',
        builder: (_, state) {
          final params = state.uri.queryParameters;
          return BiometricScreen(
            userId: params['user_id'] ?? '',
            credentialId: params['credential_id'] ?? '',
            deviceFingerprint: params['device_fingerprint'] ?? '',
          );
        },
      ),
      GoRoute(
        path: '/auth/locked',
        builder: (_, __) => const AccountLockedScreen(),
      ),
      GoRoute(
        path: '/auth/sessions',
        builder: (_, __) => const SessionsScreen(),
      ),

      // ── Authenticated routes ─────────────────────────────────────────────
      GoRoute(
        path: '/dashboard',
        builder: (_, __) => const _DashboardStub(),
      ),
    ],
    errorBuilder: (_, state) => Scaffold(
      body: Center(child: Text('Page not found: ${state.error}')),
    ),
  );
});

/// Riverpod-aware ChangeNotifier that triggers go_router re-evaluation
/// whenever the auth state changes.
class _RouterNotifier extends ChangeNotifier {
  _RouterNotifier(this._ref) {
    _ref.listen<AsyncValue<AuthState>>(
      authStateNotifierProvider,
      (_, __) => notifyListeners(),
    );
  }

  final Ref _ref;

  String? redirect(BuildContext context, GoRouterState state) {
    final authValue = _ref.read(authStateNotifierProvider);

    // Don't redirect while loading initial state.
    if (authValue.isLoading) return null;

    final authState = authValue.valueOrNull;
    final loc = state.matchedLocation;

    return switch (authState) {
      null => null,
      AuthAuthenticated() => _isAuthOnlyRoute(loc) ? '/dashboard' : null,
      AuthUnauthenticated() =>
        _isProtectedRoute(loc) ? '/auth/register' : null,
      AuthOtpPending() => loc != '/auth/otp' ? '/auth/otp' : null,
      AuthPinPending() => loc != '/auth/pin/set' ? '/auth/pin/set' : null,
      AuthMfaPending() =>
        loc != '/auth/mfa/verify' ? '/auth/mfa/verify' : null,
      AuthLocked() =>
        (loc != '/auth/locked' && loc != '/auth/pin/unblock')
            ? '/auth/locked'
            : null,
      AuthPinUnblockPending() =>
        loc != '/auth/pin/unblock' ? '/auth/pin/unblock' : null,
      AuthDeviceVerificationRequired() =>
        loc != '/auth/otp' ? '/auth/otp' : null,
      AuthStepUpPending() =>
        null, // Overlay shown on current page; no route redirect.
    };
  }

  static bool _isAuthOnlyRoute(String loc) =>
      loc.startsWith('/auth/register') ||
      loc.startsWith('/auth/login') ||
      loc.startsWith('/auth/otp');

  static bool _isProtectedRoute(String loc) =>
      loc.startsWith('/dashboard') ||
      loc.startsWith('/auth/pin/change') ||
      loc.startsWith('/auth/sessions') ||
      loc.startsWith('/auth/mfa/enable') ||
      loc.startsWith('/auth/mfa/disable');
}

class _DashboardStub extends StatelessWidget {
  const _DashboardStub();

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Dashboard')),
      body: const Center(child: LoadingWidget(message: 'Loading…')),
    );
  }
}
